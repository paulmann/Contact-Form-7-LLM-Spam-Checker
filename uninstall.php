<?php
/**
 * Uninstall handler — removes every trace of the plugin unless the
 * administrator opted to keep data (Settings > Tools > Uninstall).
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$cf7secSettings = get_option('cf7sec_settings', []);
$cf7secKeepData = is_array($cf7secSettings) && !empty($cf7secSettings['uninstall']['keep_data']);

if (!$cf7secKeepData) {
    global $wpdb;

    foreach (['cf7sec_events', 'cf7sec_ip_rules', 'cf7sec_geo', 'cf7sec_daily'] as $cf7secTable) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$cf7secTable}"); // phpcs:ignore WordPress.DB
    }

    delete_option('cf7sec_settings');
    delete_option('cf7sec_options');            // legacy v3 option
    delete_option('cf7sec_notify_throttle');
    delete_option('cf7sec_stats_legacy');

    // Transients (rate-limit windows, API caches, debug payloads).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_cf7sec_') . '%',
            $wpdb->esc_like('_transient_timeout_cf7sec_') . '%'
        )
    );

    // Debug log directory.
    $cf7secUploads = wp_get_upload_dir();
    $cf7secLogDir  = trailingslashit($cf7secUploads['basedir']) . 'cf7sec';
    if (is_dir($cf7secLogDir)) {
        foreach (glob($cf7secLogDir . '/*') ?: [] as $cf7secFile) {
            if (is_file($cf7secFile)) {
                @unlink($cf7secFile);
            }
        }
        @rmdir($cf7secLogDir);
    }

    // Legacy v3 log directory.
    $cf7secLegacyDir = WP_CONTENT_DIR . '/cf7sec_logs';
    if (is_dir($cf7secLegacyDir)) {
        foreach (glob($cf7secLegacyDir . '/*') ?: [] as $cf7secFile) {
            if (is_file($cf7secFile)) {
                @unlink($cf7secFile);
            }
        }
        @rmdir($cf7secLegacyDir);
    }

    wp_clear_scheduled_hook('cf7sec_daily_cleanup');
}
