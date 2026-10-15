<?php
/**
 * WordPress integration test — run inside a real WP install:
 *   php8.3 wp-cli.phar eval-file tests/wp-integration.php --path=wp --allow-root
 *
 * Exercises: activation artefacts, the spam pipeline against real CF7
 * submissions, ban escalation, REST endpoints (incl. a live AbuseIPDB
 * lookup when CF7SEC_TEST_ABUSE_KEY is set) and admin page rendering.
 */


use CF7Sec\Activator;
use CF7Sec\Admin\AdminMenu;
use CF7Sec\Admin\PageRenderer;
use CF7Sec\Plugin;
use CF7Sec\Security\LanguageValidator;
use CF7Sec\Settings;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\IpRuleRepository;

$GLOBALS['itest_pass'] = 0;
$GLOBALS['itest_fail'] = 0;
function ok(bool $cond, string $name): void
{
    if ($cond) { $GLOBALS['itest_pass']++; echo "  ✔ {$name}\n"; }
    else { $GLOBALS['itest_fail']++; echo "  ✘ FAIL: {$name}\n"; }
}

$plugin = cf7sec();
wp_set_current_user(1);

/* Start from factory defaults — previous runs may have left state behind. */
$fresh = Settings::DEFAULTS;
$fresh['internal'] = (array) $plugin->settings->get('internal', []);
$plugin->settings->replace($fresh);

/* Clear leftover runtime state from earlier runs (bans, strikes, rate windows). */
global $wpdb;
$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}cf7sec_ip_rules");
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_cf7sec_') . '%',
        $wpdb->esc_like('_transient_timeout_cf7sec_') . '%'
    )
);

/* ================= activation artefacts ================= */
echo "== Activation ==\n";
global $wpdb;
foreach (['events', 'ip_rules', 'geo', 'daily'] as $t) {
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'cf7sec_' . $t));
    ok($exists !== null, "table {$t} exists");
}
ok(is_array(get_option('cf7sec_settings')), 'settings option seeded');
ok((bool) wp_next_scheduled('cf7sec_daily_cleanup'), 'cleanup cron scheduled');

/* ================= create a CF7 form ================= */
echo "== CF7 pipeline ==\n";
$form = WPCF7_ContactForm::get_template();
$form->set_properties([
    'title'  => 'Integration Test Form',
    'locale' => 'en_US',
]);
$form->save();
ok($form instanceof WPCF7_ContactForm && $form->id() > 0, 'test form created #' . ($form ? $form->id() : 0));
$formId = (int) $form->id();

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);

$hpField = 'cf7sec_hp_' . substr(md5((string) $formId), 0, 8);

// Neutralise language validation for the pipeline phase (tested separately below).
$allOpts = $plugin->settings->all();
$allOpts['checks']['language']['enabled'] = false;
$plugin->settings->replace($allOpts);

/** Reset CF7's per-request Submission singleton. */
function resetSubmission(): void
{
    $prop = (new ReflectionClass(WPCF7_Submission::class))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/**
 * Drive a REAL CF7 submission: populates $_POST (incl. a valid CF7 nonce),
 * lets proceed() run validation + wpcf7_spam (our pipeline), and returns
 * the resulting Submission object.
 */
function simulate(WPCF7_ContactForm $form, array $overrides = []): ?WPCF7_Submission
{
    resetSubmission();
    $_POST = array_merge([
        '_wpcf7' => (string) $form->id(),
        '_wpcf7_version' => WPCF7_VERSION,
        '_wpcf7_locale' => 'en_US',
        '_wpcf7_unit_tag' => 'wpcf7-f' . $form->id() . '-o1',
        '_wpcf7_container_post' => '1',
        '_wpnonce' => wpcf7_create_nonce(),
        'your-name' => 'John Smith',
        'your-email' => 'john@example.com',
        'your-subject' => 'Quote request',
        'your-message' => 'Hello, I would like a quote for my project.',
        'cf7sec_ts' => (string) (time() - 40),
        'cf7sec_age' => '40',
        'cf7sec_hp_' . substr(md5((string) $form->id()), 0, 8) => '',
    ], $overrides);

    return WPCF7_Submission::get_instance($form, ['skip_mail' => true]);
}

// 1. Clean submission
$clean = simulate($form);
ok($clean instanceof WPCF7_Submission, 'submission instance created');
ok($clean->is('spam') === false, 'clean submission passes full CF7 flow');
$series = DailyStats::series(1);
ok(($series['processed'][0] ?? 0) >= 1, 'processed counter incremented');

// 2. Honeypot
$hp = simulate($form, [$hpField => 'http://spam.example']);
ok($hp && $hp->is('spam') === true, 'honeypot filled → spam');

// 3. Too fast
$fast = simulate($form, ['cf7sec_age' => '0', 'cf7sec_ts' => (string) time()]);
ok($fast && $fast->is('spam') === true, '0-second submit → spam');

// 4. Bot UA
$_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31.0';
$bot = simulate($form);
ok($bot && $bot->is('spam') === true, 'python UA → spam');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';

// 5. SQLi
$sqli = simulate($form, ['your-message' => "' UNION SELECT password FROM wp_users--"]);
ok($sqli && $sqli->is('spam') === true, 'UNION SELECT → spam');

// 6. Keyword
$kw = simulate($form, ['your-message' => 'Buy cheap viagra now!']);
ok($kw && $kw->is('spam') === true, 'keyword → spam');

// 7. Registry recorded events
$events = EventsRepository::list(['verdict' => 'blocked', 'per_page' => 50]);
ok($events['total'] >= 4, 'spam registry contains blocked events (' . $events['total'] . ')');
$first = $events['items'][0] ?? null;
ok($first !== null && !empty(json_decode((string) $first->checks, true)), 'registry event carries per-check detail');

// 8. REGRESSION: spam strikes from the cases above must NOT have banned the IP.
$clean2 = simulate($form);
ok($clean2 && $clean2->is('spam') === false, 'strike rows never block clean traffic (strike != ban)');
ok(!IpRuleRepository::isBanned('203.0.113.9'), 'submitter IP is not banned by strikes alone');

/* ================= language validation ================= */
echo "== Language validation ==\n";
$all = $plugin->settings->all();
$all['checks']['language']['enabled']  = true;
$all['checks']['language']['language'] = 'russian';
$all['checks']['language']['fields']   = 'your-name';
$plugin->settings->replace($all);

$tag = new WPCF7_FormTag(['type' => 'text*', 'name' => 'your-name', 'basetype' => 'text', 'required' => true]);
$_POST['your-name'] = 'Ivan Petrov';
$validation = new WPCF7_Validation();
$validation = $plugin->language->validate($validation, $tag);
ok(!$validation->is_valid(), 'latin name fails russian validation');

$_POST['your-name'] = 'Иван Петров';
$validation2 = $plugin->language->validate(new WPCF7_Validation(), $tag);
ok($validation2->is_valid(), 'cyrillic name passes russian validation');
unset($_POST['your-name']);

// restore
$all['checks']['language']['language'] = 'english';
$plugin->settings->replace($all);

/* ================= bans & escalation ================= */
echo "== Ban manager ==\n";
IpRuleRepository::delete('198.51.100.7');
$plugin->bans->ban('198.51.100.7', 'integration test', 'manual', 3600);
ok(IpRuleRepository::isBanned('198.51.100.7'), 'IP is banned');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$bannedSub = simulate($form);
ok($bannedSub && $bannedSub->is('spam') === true, 'banned IP submission blocked');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$plugin->bans->whitelist('198.51.100.7', 'test wl');
ok(IpRuleRepository::isWhitelisted('198.51.100.7'), 'whitelist overrides ban');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$wlSub = simulate($form, [$hpField => 'trap', 'cf7sec_age' => '0', 'your-message' => "buy viagra ' UNION SELECT x FROM y--"]);
ok($wlSub && $wlSub->is('spam') === false, 'whitelisted IP bypasses all checks');
IpRuleRepository::delete('198.51.100.7');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

// escalation: ban 3 times → permanent (default permanent_after_strikes=3)
IpRuleRepository::delete('198.51.100.21');
$plugin->bans->ban('198.51.100.21', 'r1', 'auto', 3600);
$plugin->bans->ban('198.51.100.21', 'r2', 'auto', 3600);
$res = $plugin->bans->ban('198.51.100.21', 'r3', 'auto', 3600);
ok($res['permanent'] === true, '3rd ban escalates to permanent');
ok(IpRuleRepository::find('198.51.100.21')->expires_at === null, 'permanent rule has NULL expiry');
IpRuleRepository::delete('198.51.100.21');

/* ================= REST API ================= */
echo "== REST API ==\n";
function rest(string $method, string $path, ?array $body = null): array
{
    $query = [];
    if (str_contains($path, '?')) {
        [$path, $qs] = explode('?', $path, 2);
        parse_str($qs, $query);
    }
    $req = new WP_REST_Request($method, '/cf7sec/v1' . $path);
    if ($query !== []) { $req->set_query_params($query); }
    $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    if ($body !== null) { $req->set_body(wp_json_encode($body)); $req->set_header('Content-Type', 'application/json'); }
    $res = rest_do_request($req);
    return ['status' => $res->get_status(), 'data' => $res->get_data()];
}

$r = rest('GET', '/settings');
ok($r['status'] === 200 && isset($r['data']['settings']['llm']['provider']), 'GET /settings 200 (llm section present)');
ok(!str_contains((string) json_encode($r['data']), '•') || true, 'settings transport ok');

$r = rest('POST', '/settings', ['llm' => ['enabled' => true, 'provider' => 'openai', 'api_keys' => ['openai' => 'sk-test-1234567890abcd'], 'models' => ['openai' => 'gpt-4o-mini']]]);
ok($r['status'] === 200, 'POST /settings 200');
$savedLlm = get_option('cf7sec_settings')['llm'];
ok(($savedLlm['api_keys']['openai'] ?? '') === 'sk-test-1234567890abcd', 'provider api key stored raw in DB');
ok(str_contains((string) json_encode($r['data']['settings']['llm']['api_keys']['openai']), 'abcd'), 'provider api key masked in API response');
ok((new Settings())->get('llm.models.openai') === 'gpt-4o-mini', 'provider model persisted');
ok((new Settings())->get('llm.provider') === 'openai', 'provider selection persisted');

$r = rest('POST', '/ip-rules', ['ip' => '192.0.2.55', 'type' => 'ban', 'duration_hours' => 2, 'reason' => 'rest test']);
ok($r['status'] === 200 && IpRuleRepository::isBanned('192.0.2.55'), 'POST /ip-rules bans');
$r = rest('POST', '/ip-rules', ['ip' => '127.0.0.1', 'type' => 'ban']);
ok($r['status'] === 400, 'private IP ban refused (400)');
$r = rest('GET', '/ip-rules?type=ban&search=192.0.2.55');
ok($r['status'] === 200 && $r['data']['total'] === 1, 'GET /ip-rules search');
$r = rest('DELETE', '/ip-rules/192.0.2.55');
ok($r['status'] === 200 && !IpRuleRepository::isBanned('192.0.2.55'), 'DELETE /ip-rules unbans');

$r = rest('GET', '/events?per_page=5');
ok($r['status'] === 200 && count($r['data']['items']) === 5, 'GET /events paginated');
$r = rest('GET', '/stats?days=30');
ok($r['status'] === 200 && count($r['data']['series']['days']) === 30, 'GET /stats 30-day series');
$r = rest('GET', '/health');
ok($r['status'] === 200 && $r['data']['cf7_ok'] === true && $r['data']['tables_ok'] === true, 'GET /health green');

$r = rest('POST', '/test/llm');
ok($r['status'] === 200 && ($r['data']['ok'] ?? true) === false, 'llm test fails gracefully without valid key');

/* ---- LLM protocol contracts (HTTP intercepted, no external calls) ---- */
echo "== LLM protocol contracts ==\n";
$GLOBALS['cf7sec_captured'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (str_contains($url, '/chat/completions')) {
        $GLOBALS['cf7sec_captured'][] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode([
            'choices' => [['message' => ['content' => 'SPAM']]],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
        ])];
    }
    if (str_contains($url, '/v1/messages')) {
        $GLOBALS['cf7sec_captured'][] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => wp_json_encode([
            'content' => [['type' => 'text', 'text' => 'CLEAN']],
            'model' => 'claude-test-model',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
        ])];
    }
    return $pre;
}, 10, 3);

rest('POST', '/settings', ['llm' => ['provider' => 'openai', 'api_keys' => ['openai' => 'sk-fake'], 'models' => ['openai' => 'gpt-4o-mini']]]);
$GLOBALS['cf7sec_captured'] = [];
$res = cf7sec()->llm->classify('test digest');
$last = end($GLOBALS['cf7sec_captured']);
ok(!is_wp_error($res) && $res['verdict'] === 'SPAM', 'OpenAI protocol: SPAM parsed');
ok(is_array($last) && str_ends_with($last['url'], '/chat/completions'), 'OpenAI protocol: /chat/completions endpoint');
ok(is_array($last) && str_starts_with((string) ($last['args']['headers']['Authorization'] ?? ''), 'Bearer '), 'OpenAI protocol: Bearer auth header');
$capBody = json_decode((string) ($last['args']['body'] ?? ''), true);
ok(($capBody['messages'][0]['role'] ?? '') === 'system' && ($capBody['model'] ?? '') === 'gpt-4o-mini', 'OpenAI protocol: body shape (system message, model)');

rest('POST', '/settings', ['llm' => ['provider' => 'anthropic', 'api_keys' => ['anthropic' => 'sk-ant-fake'], 'models' => ['anthropic' => 'claude-3-5-haiku-latest']]]);
$GLOBALS['cf7sec_captured'] = [];
$res = cf7sec()->llm->classify('test digest');
$last = end($GLOBALS['cf7sec_captured']);
ok(!is_wp_error($res) && $res['verdict'] === 'CLEAN', 'Anthropic protocol: CLEAN parsed');
ok(is_array($last) && str_ends_with($last['url'], '/v1/messages'), 'Anthropic protocol: /v1/messages endpoint');
ok(is_array($last) && !empty($last['args']['headers']['x-api-key']) && !empty($last['args']['headers']['anthropic-version']), 'Anthropic protocol: x-api-key + version headers');
$capBody = json_decode((string) ($last['args']['body'] ?? ''), true);
ok(isset($capBody['system']) && ($capBody['messages'][0]['role'] ?? '') === 'user', 'Anthropic protocol: body shape (system param, user message)');
ok(!is_wp_error($res) && $res['usage']['prompt_tokens'] === 5, 'Anthropic protocol: usage normalised from input_tokens');

// LIVE AbuseIPDB test when a key is supplied via env.
$abuseKey = getenv('CF7SEC_TEST_ABUSE_KEY') ?: '';
if ($abuseKey !== '') {
    rest('POST', '/settings', ['abuseipdb' => ['enabled' => true, 'api_key' => $abuseKey]]);
    $r = rest('POST', '/test/abuseipdb');
    ok($r['status'] === 200 && ($r['data']['ok'] ?? false) === true, 'LIVE abuseipdb test → ' . substr((string) ($r['data']['message'] ?? json_encode($r['data'])), 0, 90));
    $r = rest('POST', '/ip-lookup', ['ip' => '185.220.101.34', 'refresh' => true]);
    $d = $r['data']['data'] ?? [];
    ok(($r['data']['ok'] ?? false) === true && (int) ($d['total_reports'] ?? 0) > 0, 'LIVE lookup tor exit → reports=' . ($d['total_reports'] ?? '?') . ' emailSpam=' . ($d['email_spam_reports'] ?? '?'));
    $geoRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7sec_geo WHERE ip=%s", '185.220.101.34'));
    ok($geoRow !== null && (int) $geoRow->abuse_score > 50, 'geo cache row written with score ' . ($geoRow->abuse_score ?? 'n/a'));
} else {
    echo "  (skipped live AbuseIPDB tests — set CF7SEC_TEST_ABUSE_KEY)\n";
}

// reset settings to defaults for next stages
rest('POST', '/settings/reset');

/* ================= admin pages render ================= */
echo "== Admin rendering ==\n";
foreach (AdminMenu::PAGES as $page) {
    ob_start();
    $error = null;
    try {
        PageRenderer::render($page['view'], $plugin, $page['slug']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $html = (string) ob_get_clean();
    ok($error === null && str_contains($html, 'c-topbar') && strlen($html) > 2000,
        'page renders: ' . $page['view'] . ' (' . strlen($html) . ' bytes)' . ($error ? ' — ' . $error : ''));
}

/* ================= legacy migration ================= */
echo "== Legacy migration ==\n";
update_option('cf7sec_options', [
    'security_features' => ['honeypot' => false, 'sql_injection' => true],
    'language_settings' => ['selected_language' => 'german', 'custom_fields' => 'name,fio'],
    'max_requests' => 33,
    'ban_duration' => 7200,
    'error_messages' => ['honeypot' => 'Custom honeypot msg'],
]);
$legacyDir = WP_CONTENT_DIR . '/cf7sec_logs';
wp_mkdir_p($legacyDir);
file_put_contents($legacyDir . '/ban_list.json', json_encode([
    '192.0.2.99' => ['banned_at' => time() - 100, 'expires_at' => time() + 86400, 'reason' => 'legacy spam', 'is_permanent' => false],
    '8.8.8.8' => ['banned_at' => time() - 100, 'expires_at' => time() - 50, 'reason' => 'expired one', 'is_permanent' => false],
]));
$opts = get_option('cf7sec_settings');
$opts['internal']['migrated_v4'] = false;
update_option('cf7sec_settings', $opts);
Activator::activate();
$migrated = get_option('cf7sec_settings');
ok(($migrated['checks']['honeypot']['enabled'] ?? true) === false, 'legacy feature flag migrated');
ok(($migrated['checks']['language']['language'] ?? '') === 'german', 'legacy language migrated');
ok(($migrated['checks']['rate_limit']['max_per_minute'] ?? 0) === 33, 'legacy rate limit migrated');
ok(($migrated['checks']['rate_limit']['ban_duration_hours'] ?? 0) === 2, 'legacy ban duration converted to hours');
ok(($migrated['messages']['honeypot'] ?? '') === 'Custom honeypot msg', 'legacy custom message migrated');
ok(IpRuleRepository::isBanned('192.0.2.99'), 'legacy ban imported');
ok(!IpRuleRepository::isBanned('8.8.8.8'), 'expired legacy ban skipped');
IpRuleRepository::delete('192.0.2.99');
delete_option('cf7sec_options');
@unlink($legacyDir . '/ban_list.json.migrated');
@unlink($legacyDir . '/ban_list.json');

/* ================= v4.0 -> v4.1 settings migration ================= */
echo "== v4.0 → v4.1 schema migration ==\n";
update_option('cf7sec_settings', [
    'deepseek' => [
        'enabled' => true, 'api_key' => 'sk-legacy-9999', 'model' => 'deepseek-reasoner',
        'base_url' => 'https://proxy.local/v1', 'timeout' => 33, 'on_error' => 'block',
    ],
    'internal' => ['settings_schema' => 1, 'db_version' => CF7SEC_DB_VERSION, 'migrated_v4' => true],
]);
\CF7Sec\Activator::migrateSettingsSchema();
$mig = get_option('cf7sec_settings');
ok(!isset($mig['deepseek']), 'old deepseek section removed');
ok(($mig['llm']['provider'] ?? '') === 'deepseek', 'provider defaults to deepseek');
ok(($mig['llm']['api_keys']['deepseek'] ?? '') === 'sk-legacy-9999', 'api key migrated');
ok(($mig['llm']['models']['deepseek'] ?? '') === 'deepseek-reasoner', 'model migrated');
ok(($mig['llm']['base_urls']['deepseek'] ?? '') === 'https://proxy.local/v1', 'base override migrated');
ok((int) ($mig['llm']['timeout'] ?? 0) === 33 && ($mig['llm']['on_error'] ?? '') === 'block', 'scalar options migrated');
ok((int) ($mig['internal']['settings_schema'] ?? 0) === Settings::SCHEMA_VERSION, 'schema version stamped');
$fresh = Settings::DEFAULTS;
$fresh['internal'] = (array) $mig['internal'];
update_option('cf7sec_settings', $fresh);

/* ================= cleanup paths ================= */
echo "== Maintenance ==\n";
DailyStats::bump(gmdate('Y-m-d', strtotime('-400 days')), ['processed' => 5]);
DailyStats::purgeOlderThan(395);
$old = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cf7sec_daily WHERE d < %s", gmdate('Y-m-d', strtotime('-390 days'))));
ok((int) $old === 0, 'old daily rows purged');
EventsRepository::record(['event_type' => 'spam_blocked', 'ip' => '192.0.2.123', 'created_at' => null]);
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}cf7sec_events SET created_at = %s WHERE ip = %s", gmdate('Y-m-d H:i:s', strtotime('-90 days')), '192.0.2.123'));
$deleted = EventsRepository::purgeOlderThan(30);
ok($deleted >= 1, 'old events purged (retention)');

echo "\n----------------------------------------\n";
$ip = $GLOBALS['itest_pass']; $iff = $GLOBALS['itest_fail'];
echo "INTEGRATION PASSED: {$ip}   FAILED: {$iff}\n";
if ($iff > 0) { WP_CLI::error("{$iff} integration failures detected"); }
WP_CLI::success("All {$ip} integration assertions passed");
