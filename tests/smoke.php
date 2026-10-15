<?php
/**
 * Smoke tests for pure-logic components (no WordPress, no DB, no HTTP).
 *
 * Run:  php8.3 tests/smoke.php
 */

declare(strict_types=1);

require __DIR__ . '/wp-stubs.php';

use CF7Sec\Languages;
use CF7Sec\Security\Checks\BotAgentCheck;
use CF7Sec\Security\Checks\HoneypotCheck;
use CF7Sec\Security\Checks\KeywordSpamCheck;
use CF7Sec\Security\Checks\LinkSpamCheck;
use CF7Sec\Security\Checks\LlmCheck;
use CF7Sec\Security\Checks\RateLimitCheck;
use CF7Sec\Security\Checks\SqlInjectionCheck;
use CF7Sec\Security\Checks\TimeCheck;
use CF7Sec\Security\Checks\XssCheck;
use CF7Sec\Security\CheckStatus;
use CF7Sec\Security\LlmPrompt;
use CF7Sec\Security\SubmissionContext;
use CF7Sec\Services\Llm\LlmGateway;
use CF7Sec\Services\Llm\ProviderCatalog;
use CF7Sec\Settings;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Support\Ip;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✔ {$name}\n";
    } else {
        $failed++;
        echo "  ✘ FAIL: {$name}\n";
    }
}

function makeContext(array $posted = [], array $overrides = [], ?Settings $settings = null): SubmissionContext
{
    $settings ??= new Settings();

    return new SubmissionContext(
        formId: 42,
        formTitle: 'Contact',
        postedData: $posted,
        ip: '203.0.113.9',
        userAgent: $overrides['ua'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        referrer: 'https://example.test/contact',
        serverTime: $overrides['now'] ?? 1700000000,
        settings: $settings,
    );
}

echo "== S1: csvCell() spreadsheet-injection guard ==\n";
foreach (['=', '+', '-', '@', "\t", "\r", "\n"] as $lead) {
    $cell = $lead . 'cmd';
    ok(
        EventsRepository::csvCell($cell) === "'" . $cell,
        'leading ' . json_encode($lead) . ' gets the defensive prefix'
    );
}
ok(EventsRepository::csvCell('=1+1') === "'=1+1", 'formula-looking cell prefixed');
ok(EventsRepository::csvCell('@SUM(A1)') === "'@SUM(A1)", 'at-sign cell prefixed');
ok(EventsRepository::csvCell('name=value') === 'name=value', 'formula characters not in first position stay untouched');
ok(EventsRepository::csvCell('203.0.113.9') === '203.0.113.9', 'ordinary text untouched');
ok(EventsRepository::csvCell('') === '', 'empty cell untouched');
$exported = [];
foreach (EventsRepository::exportCsv() as $row) {
    $exported[] = $row;
    break;
}
ok(isset($exported[0][0]) && count($exported[0]) === 11, 'exportCsv header still has all 11 columns');

echo "== Settings ==\n";
$s = new Settings();
ok($s->get('checks.time_check.min_seconds') === 3, 'defaults: min_seconds = 3');
ok($s->get('llm.provider') === 'deepseek', 'defaults: llm provider = deepseek');
ok($s->get('nonexistent.path', 'fb') === 'fb', 'missing path returns default');
ok(
    $s->get('checks.language.language') === Settings::DEFAULTS['checks']['language']['language'],
    'default language comes from Settings::DEFAULTS'
);
ok(
    Settings::DEFAULTS['checks']['language']['language'] === Languages::DEFAULT_LANG,
    'DEFAULTS and Languages::DEFAULT_LANG cannot drift (P3)'
);

$raw = [
    'checks' => ['time_check' => ['enabled' => true, 'min_seconds' => 99999]],
    'abuseipdb' => ['api_key' => 'sk-abc123456789', 'min_confidence' => '75'],
    'llm' => [
        'provider'  => 'qwen',
        'api_keys'  => ['qwen' => 'sk-qwen-1234567890', 'openai' => Settings::MASK . 'abcd'],
        'models'    => ['qwen' => 'qwen-max'],
        'base_urls' => ['custom_openai' => 'javascript:x', 'groq' => 'https://proxy.example.com/v1'],
    ],
    'general' => ['retention_days' => '10', 'form_ids' => ['3', 'x', '-2', '3']],
];
$sanitized = $s->sanitize($raw);
ok($sanitized['checks']['time_check']['min_seconds'] === 300, 'min_seconds clamped to 300');
ok($sanitized['abuseipdb']['api_key'] === 'sk-abc123456789', 'api key stored');
ok($sanitized['abuseipdb']['min_confidence'] === 75, 'confidence cast to int');
ok($sanitized['llm']['provider'] === 'qwen', 'llm provider kept');
ok($sanitized['llm']['api_keys']['qwen'] === 'sk-qwen-1234567890', 'provider key stored');
ok($sanitized['llm']['api_keys']['openai'] === '', 'masked provider key never stored over empty');
ok($sanitized['llm']['models']['qwen'] === 'qwen-max', 'provider model kept');
ok(($sanitized['llm']['base_urls']['custom_openai'] ?? 'x') === 'x' || $sanitized['llm']['base_urls']['custom_openai'] === '', 'malicious llm base_url ignored');
ok($sanitized['llm']['base_urls']['groq'] === 'https://proxy.example.com/v1', 'llm base override kept');
ok((new Settings())->sanitize(['llm' => ['provider' => 'evil']])['llm']['provider'] === 'deepseek', 'unknown provider rejected');
ok($sanitized['general']['form_ids'] === [3, 2], 'form_ids sanitised+deduped');
ok(Settings::maskSecret('sk-secret-key-9876') === Settings::MASK . '9876', 'maskSecret keeps last 4');
ok(
    (new Settings())->sanitize(['checks' => ['language' => ['language' => 'klingon']]])['checks']['language']['language']
        === Settings::DEFAULTS['checks']['language']['language'],
    'invalid language falls back to the DEFAULTS value (P3)'
);
ok(
    (new Settings())->sanitize(['checks' => ['language' => ['language' => 'german']]])['checks']['language']['language'] === 'german',
    'valid language is kept (P3)'
);

echo "== LLM provider catalog ==\n";
ok(count(ProviderCatalog::ids()) === 11, '11 providers in catalog');
ok(ProviderCatalog::get('anthropic')['protocol'] === 'anthropic', 'anthropic uses Messages protocol');
ok(ProviderCatalog::get('deepseek')['protocol'] === 'openai', 'deepseek uses OpenAI protocol');
ok(ProviderCatalog::get('qwen')['protocol'] === 'openai', 'qwen uses OpenAI-compatible protocol');
ok(ProviderCatalog::get('custom_openai')['base'] === '', 'custom provider has empty default base');
ok(ProviderCatalog::exists('nope') === false, 'unknown provider id rejected');

echo "== Support\\Ip ==\n";
ok(Ip::valid('8.8.8.8') === '8.8.8.8' && Ip::valid('nope') === '', 'valid()');
ok(Ip::firstFromList('10.0.0.1, 93.184.216.34, 8.8.8.8') === '93.184.216.34', 'firstFromList prefers public');
ok(Ip::firstFromList('1.2.3.4, 203.0.113.9') === '1.2.3.4', 'M12: spoofed XFF list — first public entry wins (documented risk)');
ok(Ip::firstFromList('203.0.113.9, 1.2.3.4') === '203.0.113.9', 'M12: client-position entry wins when listed first');
ok(Ip::isPrivate('192.168.1.5') === true && Ip::isPrivate('8.8.8.8') === false, 'isPrivate()');
ok(Ip::isCloudflare('104.16.5.5') === true && Ip::isCloudflare('8.8.8.8') === false, 'isCloudflare()');
ok(Ip::inCidr('172.64.0.1', '172.64.0.0/13') === true && Ip::inCidr('172.72.0.1', '172.64.0.0/13') === false, 'inCidr()');
ok(Ip::mask('1.2.3.4') === '1.2.x.x', 'mask()');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '93.184.216.34';
ok(Ip::client('auto') === '93.184.216.34', 'auto mode trusts CF header from loopback proxy');
ok(Ip::client('remote_only') === '127.0.0.1', 'remote_only ignores headers');
$_SERVER['REMOTE_ADDR'] = '93.184.216.34'; // direct connection
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6'; // attacker-spoofed
ok(Ip::client('auto') === '93.184.216.34', 'auto mode ignores spoofable header from public REMOTE_ADDR');
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);

echo "== LLM verdict parsing ==\n";
ok(LlmGateway::parseVerdict('SPAM') === 'SPAM', 'exact SPAM');
ok(LlmGateway::parseVerdict(' clean ') === 'CLEAN', 'lowercase clean');
ok(LlmGateway::parseVerdict('CLEAN.') === 'CLEAN', 'trailing punctuation');
ok(LlmGateway::parseVerdict('The answer is: SPAM!') === 'SPAM', 'sentence wrapper');
ok(LlmGateway::parseVerdict('SPAM or CLEAN?') === null, 'ambiguous rejected');
ok(LlmGateway::parseVerdict('maybe?') === null, 'garbage rejected');
ok(LlmGateway::parseVerdict('') === null, 'empty rejected');

echo "== TimeCheck ==\n";
$tc = new TimeCheck();
$r = $tc->run(makeContext(['cf7sec_age' => '1']));
ok($r->isFail() && $r->messageKey === 'time_check', 'too fast (1s) fails');
$r = $tc->run(makeContext(['cf7sec_age' => '35']));
ok($r->status === CheckStatus::Pass, '35s passes');
$r = $tc->run(makeContext(['cf7sec_age' => '99999']));
ok($r->isFail(), 'ancient age fails');
$r = $tc->run(makeContext(['cf7sec_ts' => 1700000000 - 1], ['now' => 1700000000]));
ok($r->isFail(), 'render-ts 1s delta fails');
$r = $tc->run(makeContext(['cf7sec_ts' => 1700000000 - 60], ['now' => 1700000000]));
ok($r->status === CheckStatus::Pass, 'render-ts fallback 60s passes');
$r = $tc->run(makeContext([]));
ok($r->status === CheckStatus::Skip, 'no fields -> skip');

echo "== HoneypotCheck ==\n";
$hp = new HoneypotCheck();
$field = HoneypotCheck::fieldName(42);
ok($hp->run(makeContext([$field => '']))->status === CheckStatus::Pass, 'empty passes');
ok($hp->run(makeContext([$field => 'http://spam.example']))->isFail(), 'filled fails');
ok($hp->run(makeContext([]))->status === CheckStatus::Skip, 'missing skips');

echo "== SqlInjectionCheck ==\n";
$sqli = new SqlInjectionCheck();
ok($sqli->run(makeContext(['message' => "Robert'); DROP TABLE students;--"]))->isFail(), 'stacked DROP TABLE fails');
ok($sqli->run(makeContext(['message' => "' OR 1=1 --"]))->isFail(), 'tautology fails');
ok($sqli->run(makeContext(['message' => 'UNION ALL SELECT password FROM users']))->isFail(), 'UNION SELECT fails');
ok($sqli->run(makeContext(['message' => 'Hello, I would like to update my address and delete my old order.']))->status === CheckStatus::Pass, 'natural prose passes');
ok($sqli->run(makeContext(['message' => 'Can we select from your catalogue?']))->status === CheckStatus::Pass, 'select from prose passes');
ok($sqli->run(makeContext(['message' => 'select password from wp_users']))->isFail(), 'wp table probe fails');
ok($sqli->run(makeContext(['message' => 'query information_schema.tables']))->isFail(), 'information_schema probe fails');

echo "== XssCheck ==\n";
$xss = new XssCheck();
ok($xss->run(makeContext(['message' => '<script>alert(1)</script>']))->isFail(), 'script tag fails');
ok($xss->run(makeContext(['message' => '<img src=x onerror=alert(1)>']))->isFail(), 'img onerror fails');
ok($xss->run(makeContext(['message' => '%3Cscript%3Ealert(1)%3C/script%3E']))->isFail(), 'url-encoded script fails');
ok($xss->run(makeContext(['message' => 'Here is my data: 3 files, see javascript of the course']))->status === CheckStatus::Pass, 'prose with data:/javascript words passes');
ok($xss->run(makeContext(['message' => 'Price < 100 and width > 5']))->status === CheckStatus::Pass, 'math comparisons pass');

echo "== BotAgentCheck ==\n";
$bot = new BotAgentCheck();
ok($bot->run(makeContext([], ['ua' => '']))->isFail(), 'empty UA fails');
ok($bot->run(makeContext([], ['ua' => 'curl/8.4.0']))->isFail(), 'curl fails');
ok($bot->run(makeContext([], ['ua' => 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120']))->isFail(), 'headless fails');
ok($bot->run(makeContext([], ['ua' => 'python-requests/2.31']))->isFail(), 'python-requests fails');
ok($bot->run(makeContext([], ['ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1']))->status === CheckStatus::Pass, 'iPhone Safari passes');
ok($bot->run(makeContext([], ['ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0']))->status === CheckStatus::Pass, 'Edge passes');

echo "== LinkSpamCheck ==\n";
$ls = new LinkSpamCheck();
ok($ls->run(makeContext(['message' => 'See https://a.com and https://b.com and www.c.com']))->isFail(), '3 links fail (max 2)');
ok($ls->run(makeContext(['message' => 'See https://a.com']))->status === CheckStatus::Pass, '1 link passes');

echo "== Keyword matcher ==\n";
// Mechanism only: the tokens come from the owner-maintained pattern bank
// (docs/spam-patterns.md) or are synthetic and harmless. No pattern is
// invented here, and none is printed.
$bankFile = dirname(__DIR__) . '/docs/spam-patterns.md';
ok(is_readable($bankFile), 'pattern bank docs/spam-patterns.md is readable');
$bank = [];
if (is_readable($bankFile)) {
    foreach (preg_split('/\R/', (string) file_get_contents($bankFile)) ?: [] as $line) {
        // Only lines made exclusively of backticked tokens are pattern rows;
        // inline `code` inside prose must not be mistaken for a pattern.
        if (preg_match('/^(?:\s*`[^`]+`\s*,?)*\s*`[^`]+`\s*,?\s*$/u', $line) !== 1) {
            continue;
        }
        if (preg_match_all('/`([^`]+)`/u', $line, $m)) {
            foreach ($m[1] as $token) {
                $bank[mb_strtolower(trim($token))] = trim($token);
            }
        }
    }
}
ok($bank !== [], 'pattern bank contributes tokens (' . count($bank) . ')');
$bankTokens = array_values($bank);
$longTokens = array_values(array_filter($bankTokens, static fn(string $t): bool => mb_strlen($t) >= KeywordSpamCheck::MIN_KEYWORD_LENGTH));
ok($longTokens !== [], 'pattern bank has tokens long enough to match');

$sample = array_slice($longTokens, 0, 6);
foreach ($sample as $token) {
    $found = KeywordSpamCheck::matches('Placeholder text before ' . $token . ' and after.', [$token]);
    ok($found === [$token], 'bank token is matched verbatim');
}
foreach (array_slice($sample, 0, 3) as $token) {
    $found = KeywordSpamCheck::matches('Placeholder text before ' . mb_strtoupper($token) . ' and after.', [$token]);
    ok($found === [$token], 'bank token is matched case-insensitively');
}
$phrase = '';
foreach ($bankTokens as $token) {
    if (str_contains(trim($token), ' ')) {
        $phrase = $token;
        break;
    }
}
ok($phrase !== '', 'pattern bank contains a multi-word phrase');
if ($phrase !== '') {
    ok(KeywordSpamCheck::matches('Some ' . $phrase . ' here', [strtoupper($phrase)]) === [strtoupper($phrase)], 'multi-word phrase matched, case-insensitive');
}
ok(KeywordSpamCheck::matches('A neutral sentence about the weather and a shopping list.', $bankTokens) === [], 'benign synthetic text produces no match');
ok(KeywordSpamCheck::matches('Anything at all', []) === [], 'empty keyword list matches nothing');
ok(KeywordSpamCheck::matches('ab', ['ab'], 1) === ['ab'], 'short token honoured when the minimum is lowered');
ok(KeywordSpamCheck::matches('ab', ['ab']) === [], 'short token ignored at the default minimum');
ok(KeywordSpamCheck::matches('text', ['  text  ']) === ['text'], 'keywords are trimmed before matching');

$ks = new KeywordSpamCheck();
ok(KeywordSpamCheck::parseList("viagra\ncasino, lottery\n\n") === ['viagra', 'casino', 'lottery'], 'parseList splits lines+commas');
ok(KeywordSpamCheck::parseList("alpha\n  alpha \nALPHA\nbeta") === ['alpha', 'beta'], 'parseList trims and drops duplicates case-insensitively');
ok(KeywordSpamCheck::parseList("abc\nabcd\n, ,\nabcde") === ['abcd', 'abcde'], 'parseList drops tokens shorter than 4 characters');
ok(KeywordSpamCheck::parseList("abc\nabcd", 3) === ['abc', 'abcd'], 'the minimum keyword length is configurable');
ok($ks->run(makeContext(['message' => 'Buy cheap VIAGRA today']))->isFail(), 'case-insensitive keyword fails');
ok($ks->run(makeContext(['message' => 'I need help with my account']))->status === CheckStatus::Pass, 'clean text passes');

echo "== RateLimitCheck ==\n";
// The counter itself is atomic in the storage layer and is covered by
// tests/wp-integration.php (needs a database); the boundary stays pure here.
ok(!RateLimitCheck::exceeds(20, 20), '20th request passes (limit 20)');
ok(RateLimitCheck::exceeds(21, 20), '21st request fails');
ok(!RateLimitCheck::exceeds(1, 1), 'single-request limit allows exactly one');
ok(RateLimitCheck::key('203.0.113.9') === RateLimitCheck::key('203.0.113.9'), 'counter key is stable per IP');
ok(RateLimitCheck::key('203.0.113.9') !== RateLimitCheck::key('203.0.113.10'), 'counter key differs per IP');
ok(str_starts_with(RateLimitCheck::key('203.0.113.9'), 'cf7sec_rl_'), 'counter key uses the transient prefix');

echo "== B1 regression: LlmCheck enablement under schema v2 ==\n";
$GLOBALS['cf7sec_test_options'][CF7SEC_OPTION] = Settings::deepMerge(Settings::DEFAULTS, [
    'llm' => ['enabled' => true, 'provider' => 'deepseek', 'api_keys' => ['deepseek' => 'sk-test-0001']],
]);
$s2      = new Settings();
$gateway = new LlmGateway($s2);
$llm     = new LlmCheck($gateway);
ok($gateway->isConfigured(), 'gateway configured from llm.* keys');
ok($llm->enabled(makeContext([], [], $s2)) === true, 'LlmCheck::enabled() === true with llm.enabled (B1)');
$GLOBALS['cf7sec_test_options'][CF7SEC_OPTION] = [];

echo "== M10: salted honeypot field names ==\n";
ok(HoneypotCheck::fieldName(7) === HoneypotCheck::fieldName(7), 'stable within one installation');
ok(HoneypotCheck::fieldName(7) !== HoneypotCheck::fieldName(8), 'differs per form id');
ok(!str_starts_with(HoneypotCheck::fieldName(7), '_'), 'no leading underscore (CF7 strips _ keys)');
ok(strlen(HoneypotCheck::fieldName(7)) === strlen('cf7sec_hp_') + 12, '12-char HMAC suffix');
ok(HoneypotCheck::fieldName(7) !== 'cf7sec_hp_' . substr(md5('7'), 0, 8), 'old unsalted md5 scheme retired');

echo "== Languages ==\n";
ok(preg_match(Languages::REGEX['russian']['regex'], 'Иван Петров') === 1, 'russian regex accepts cyrillic name');
ok(preg_match(Languages::REGEX['russian']['regex'], 'Ivan Petrov') === 0, 'russian regex rejects latin');
ok(preg_match(Languages::REGEX['english']['regex'], "O'Connor-Smith Jr.") === 1, 'english regex accepts punctuation');
ok(preg_match(Languages::REGEX['chinese']['regex'], '王小明') === 1, 'chinese regex accepts han');
ok(preg_match(Languages::REGEX['german']['regex'], 'Jürgen Groß') === 1, 'german regex accepts umlauts+ß');
ok(count(Languages::REGEX) === 22, '22 languages registered');

echo "== LlmPrompt digest ==\n";
$ctx = makeContext(['your-name' => 'John', 'message' => 'Hello there', 'cf7sec_age' => '42']);
$ctx->setGeo(['country_code' => 'US', 'country_name' => 'United States', 'isp' => 'Comcast', 'abuse_score' => 12, 'total_reports' => 3]);
$digest = LlmPrompt::digest($ctx);
ok(str_contains($digest, '203.0.113.9'), 'digest contains IP');
ok(str_contains($digest, 'US'), 'digest contains country');
ok(str_contains($digest, 'John'), 'digest contains field value');
ok(str_contains($digest, 'Seconds between page load and submit: 42'), 'digest contains dwell time');
ok(!str_contains($digest, '_cf7sec_age: 42'), 'control fields excluded from FIELDS section');
ok(str_contains($digest, 'SPAM or CLEAN'), 'digest ends with the verdict instruction');

echo "\n----------------------------------------\n";
echo "PASSED: {$passed}   FAILED: {$failed}\n";
exit($failed === 0 ? 0 : 1);
