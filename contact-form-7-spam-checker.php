<?php
/**
 * Plugin Name:       Spam Checker Pro for Contact Form 7
 * Plugin URI:        https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker
 * Description:       Multi-layered spam & security protection for Contact Form 7: honeypot, timing analysis, rate limiting, bot/SQLi/XSS detection, language validation, AbuseIPDB reputation checks and LLM analysis via 11 providers — with a spam registry, IP management and a modern admin dashboard.
 * Version:           4.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.3
 * Author:            Mikhail Deynekin
 * Author URI:        https://deynekin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spam-checker-for-contact-form-7
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/

define('CF7SEC_VERSION', '4.2.0');
define('CF7SEC_DB_VERSION', 410);
define('CF7SEC_MIN_PHP', '8.3');
define('CF7SEC_MIN_WP', '6.0');
define('CF7SEC_TEXTDOMAIN', 'spam-checker-for-contact-form-7');
define('CF7SEC_FILE', __FILE__);
define('CF7SEC_BASENAME', plugin_basename(__FILE__));
define('CF7SEC_DIR', plugin_dir_path(__FILE__));
define('CF7SEC_URL', plugin_dir_url(__FILE__));
define('CF7SEC_OPTION', 'cf7sec_settings');
define('CF7SEC_REST_NS', 'cf7sec/v1');

/*
|--------------------------------------------------------------------------
| Environment guard — fail gracefully on unsupported PHP versions.
|--------------------------------------------------------------------------
*/

if (PHP_VERSION_ID < 80300) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __('Spam Checker Pro for Contact Form 7 requires PHP %1$s or newer (current: %2$s). The plugin is inactive until the requirement is met.', 'spam-checker-for-contact-form-7'),
                CF7SEC_MIN_PHP,
                PHP_VERSION
            ))
        );
    });

    return; // Do not boot.
}

/*
|--------------------------------------------------------------------------
| PSR-4 autoloader: CF7Sec\ => src/
|--------------------------------------------------------------------------
*/

spl_autoload_register(static function (string $class): void {
    $prefix = 'CF7Sec\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = CF7SEC_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

// Rare hosts ship PHP without mbstring — provide best-effort polyfills.
if (!function_exists('mb_strlen')) {
    require_once CF7SEC_DIR . 'src/Support/compat.php';
}

/*
|--------------------------------------------------------------------------
| Activation / deactivation / bootstrap
|--------------------------------------------------------------------------
*/

register_activation_hook(__FILE__, [CF7Sec\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [CF7Sec\Deactivator::class, 'deactivate']);

/**
 * Global accessor for the plugin instance.
 */
function cf7sec(): CF7Sec\Plugin
{
    return CF7Sec\Plugin::instance();
}

add_action('plugins_loaded', static function (): void {
    cf7sec()->boot();
}, 5);
