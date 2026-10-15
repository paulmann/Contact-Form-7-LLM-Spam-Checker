<?php
/**
 * Minimal WordPress function stubs for standalone smoke testing.
 * NOT part of the runtime plugin — tests only.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/cf7sec-test-content');
define('CF7SEC_VERSION', '4.0.0-test');
define('CF7SEC_DB_VERSION', 410);
define('CF7SEC_MIN_PHP', '8.3');
define('CF7SEC_MIN_WP', '6.0');
define('CF7SEC_TEXTDOMAIN', 'spam-checker-for-contact-form-7');
define('CF7SEC_FILE', __DIR__);
define('CF7SEC_BASENAME', 'spam-checker-for-contact-form-7/cf7.php');
define('CF7SEC_DIR', dirname(__DIR__) . '/');
define('CF7SEC_URL', 'http://example.test/wp-content/plugins/spam-checker-for-contact-form-7/');
define('CF7SEC_OPTION', 'cf7sec_settings');
define('CF7SEC_REST_NS', 'cf7sec/v1');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

$GLOBALS['cf7sec_test_options'] = [];
$GLOBALS['cf7sec_test_transients'] = [];
/** Scheduled cron hooks: hook name => timestamp. Mirrors wp_next_scheduled(). */
$GLOBALS['cf7sec_test_scheduled'] = [];

function __($text, $domain = null) { return $text; }
function _e($text, $domain = null) { echo $text; }
function esc_html__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html_e($text, $domain = null) { echo htmlspecialchars($text, ENT_QUOTES); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return filter_var($u, FILTER_SANITIZE_URL) ?: ''; }
function esc_url_raw($u) { return filter_var($u, FILTER_SANITIZE_URL) ?: ''; }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function wp_kses_post($t) { return $t; }
function wp_specialchars_decode($t) { return htmlspecialchars_decode((string) $t); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_textarea_field($t) { return trim((string) $t); }
function sanitize_email($t) { return filter_var(trim((string) $t), FILTER_SANITIZE_EMAIL) ?: ''; }
function sanitize_key($t) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $t)) ?? ''; }
function is_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
function absint($n) { return abs((int) $n); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function number_format_i18n($n) { return number_format((float) $n); }
function wp_date($f, $ts = null) { return date($f, $ts ?? time()); }
function current_time($type, $gmt = 0) { return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s'); }
function translate_with_gettext_context($text, $ctx, $domain) { return $text; }
function wp_salt($scheme = 'auth') { return 'test-salt'; }
function home_url($p = '') { return 'http://example.test' . $p; }
function admin_url($p = '') { return 'http://example.test/wp-admin/' . $p; }
function rest_url($p = '') { return 'http://example.test/wp-json/' . $p; }
function get_bloginfo($k) { return 'Test Site'; }
function current_user_can($c) { return true; }
function wp_create_nonce($a) { return 'nonce'; }
function get_posts($a = []) { return []; }
function get_the_title($p) { return ''; }
function do_action(...$a) {}
function apply_filters($tag, $value, ...$rest) { return $value; }
function add_action(...$a) { return true; }
function add_filter(...$a) { return true; }
function register_activation_hook(...$a) {}
function register_deactivation_hook(...$a) {}
function wp_next_scheduled($h) { return $GLOBALS['cf7sec_test_scheduled'][$h] ?? false; }
function wp_schedule_event($timestamp, $recurrence, $hook) { $GLOBALS['cf7sec_test_scheduled'][$hook] = $timestamp; return true; }
function wp_clear_scheduled_hook($h) { unset($GLOBALS['cf7sec_test_scheduled'][$h]); }
function flush_rewrite_rules() {}
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }
function wp_is_writable($p) { return is_writable($p); }
function wp_get_upload_dir() { return ['basedir' => sys_get_temp_dir() . '/cf7sec-test-uploads', 'baseurl' => 'http://example.test/uploads']; }
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.test/wp-content/plugins/' . basename(dirname($f)) . '/'; }
function wp_remote_get(...$a) { return new WP_Error('stub', 'no http in smoke tests'); }
function wp_remote_post(...$a) { return new WP_Error('stub', 'no http in smoke tests'); }
function wp_remote_retrieve_response_code($r) { return 0; }
function wp_remote_retrieve_body($r) { return ''; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query((array) $args); }

function get_option($name, $default = false)
{
    return $GLOBALS['cf7sec_test_options'][$name] ?? $default;
}
function update_option($name, $value, $autoload = null)
{
    $GLOBALS['cf7sec_test_options'][$name] = $value;
    return true;
}
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (!isset($GLOBALS['cf7sec_test_options'][$name])) {
        $GLOBALS['cf7sec_test_options'][$name] = $value;
        return true;
    }
    return false;
}
function delete_option($name)
{
    unset($GLOBALS['cf7sec_test_options'][$name]);
    return true;
}
function get_transient($key)
{
    $entry = $GLOBALS['cf7sec_test_transients'][$key] ?? null;
    if ($entry === null) { return false; }
    if ($entry[1] > 0 && $entry[1] < time()) {
        unset($GLOBALS['cf7sec_test_transients'][$key]);
        return false;
    }
    return $entry[0];
}
function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['cf7sec_test_transients'][$key] = [$value, $ttl > 0 ? time() + $ttl : 0];
    return true;
}
function delete_transient($key)
{
    unset($GLOBALS['cf7sec_test_transients'][$key]);
    return true;
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed { return $this->data; }
}

/**
 * Minimal $wpdb double. Records the rows the legacy importer writes so tests
 * can assert the migration without a database; anything else is out of scope.
 */
class CF7SEC_Test_Wpdb
{
    public string $prefix = 'wp_';
    /** @var array<int, array{table: string, data: array<string, mixed>}> */
    public array $replaced = [];
    public int $rowCount = 0;

    public function replace(string $table, array $data, mixed $format = null): int
    {
        $this->replaced[] = ['table' => $table, 'data' => $data];

        return 1;
    }

    public function get_charset_collate(): string { return ''; }
}

$GLOBALS['wpdb'] = new CF7SEC_Test_Wpdb();

// PSR-4 autoloader mirroring the plugin bootstrap.
spl_autoload_register(static function (string $class): void {
    $prefix = 'CF7Sec\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) { require_once $file; }
});
