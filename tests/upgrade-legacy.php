<?php
/**
 * Reproduces defect P1: a regular plugin update (Plugins screen → Update)
 * never calls the activation hook, so the legacy v3.x import and the daily
 * cleanup cron used to be skipped — the v3.x ban list never arrived and the
 * retention job was never scheduled, letting the registry grow forever.
 *
 * Run:  php tests/upgrade-legacy.php   (exit 0 = OK)
 */

declare(strict_types=1);

require __DIR__ . '/wp-stubs.php';

use CF7Sec\Activator;
use CF7Sec\Cron;
use CF7Sec\Settings;

$errors = [];

/* ------------------------------------------------------------------
 * Site state: already on 4.x (version stamps current) but the v3.x import
 * was never performed and the cron was never scheduled.
 * ----------------------------------------------------------------*/
$installed = Settings::DEFAULTS;
$installed['internal']['db_version']      = CF7SEC_DB_VERSION;
$installed['internal']['settings_schema'] = Settings::SCHEMA_VERSION;
$installed['internal']['migrated_v4']     = false;
$GLOBALS['cf7sec_test_options'][CF7SEC_OPTION] = $installed;

/* Raw v3.x option, under its own option name. */
$GLOBALS['cf7sec_test_options']['cf7sec_options'] = [
    'security_features' => ['honeypot' => false, 'sql_injection' => true, 'rate_limiting' => true],
    'language_settings' => ['selected_language' => 'german', 'custom_fields' => 'name,fio'],
    'max_requests'      => 33,
    'ban_threshold'     => 7,
    'ban_duration'      => 7200,
    'debug_mode'        => true,
    'error_messages'    => ['honeypot' => 'Custom honeypot message'],
];

/* Legacy ban list file (v3.x storage). */
$legacyDir  = WP_CONTENT_DIR . '/cf7sec_logs';
$banFile    = $legacyDir . '/ban_list.json';
$movedFile  = $banFile . '.migrated';
@unlink($movedFile);
if (!is_dir($legacyDir)) {
    mkdir($legacyDir, 0777, true);
}
file_put_contents($banFile, (string) json_encode([
    '192.0.2.99' => ['banned_at' => time() - 100, 'expires_at' => time() + 86400, 'reason' => 'legacy spam', 'is_permanent' => false],
    '198.51.100.4' => ['banned_at' => time() - 200, 'reason' => 'legacy permanent', 'is_permanent' => true],
    '8.8.8.8' => ['banned_at' => time() - 100, 'expires_at' => time() - 50, 'reason' => 'already expired', 'is_permanent' => false],
    'not-an-ip' => ['is_permanent' => true],
]));

$GLOBALS['wpdb']->replaced = [];

/* ------------------------------------------------------------------
 * The upgrade path must fix all of it.
 * ----------------------------------------------------------------*/
Activator::maybeUpgrade();

$stored = get_option(CF7SEC_OPTION);

if (($stored['checks']['honeypot']['enabled'] ?? true) !== false) {
    $errors[] = 'legacy feature flag not migrated by maybeUpgrade()';
}
if (($stored['checks']['sql_injection']['enabled'] ?? false) !== true) {
    $errors[] = 'legacy sql_injection flag not migrated';
}
if (($stored['checks']['language']['language'] ?? '') !== 'german') {
    $errors[] = 'legacy language not migrated';
}
if (($stored['checks']['rate_limit']['max_per_minute'] ?? 0) !== 33) {
    $errors[] = 'legacy max_requests not migrated';
}
if (($stored['checks']['rate_limit']['strike_threshold'] ?? 0) !== 7) {
    $errors[] = 'legacy ban_threshold not migrated';
}
if (($stored['checks']['rate_limit']['ban_duration_hours'] ?? 0) !== 2) {
    $errors[] = 'legacy ban_duration not converted to hours';
}
if (($stored['messages']['honeypot'] ?? '') !== 'Custom honeypot message') {
    $errors[] = 'legacy custom message not migrated';
}
if (($stored['debug']['enabled'] ?? false) !== true) {
    $errors[] = 'legacy debug_mode not migrated';
}
if (($stored['internal']['migrated_v4'] ?? false) !== true) {
    $errors[] = 'migration marker not stamped';
}

$imported = [];
foreach ($GLOBALS['wpdb']->replaced as $row) {
    $imported[(string) ($row['data']['ip'] ?? '')] = $row['data'];
}
if (!isset($imported['192.0.2.99'])) {
    $errors[] = 'temporary legacy ban was not imported';
}
if (!isset($imported['198.51.100.4'])) {
    $errors[] = 'permanent legacy ban was not imported';
}
if (isset($imported['8.8.8.8'])) {
    $errors[] = 'expired legacy ban should have been skipped';
}
if (isset($imported['not-an-ip'])) {
    $errors[] = 'invalid legacy IP should have been skipped';
}
if (($imported['198.51.100.4'] ?? null) === null || !array_key_exists('expires_at', $imported['198.51.100.4']) || $imported['198.51.100.4']['expires_at'] !== null) {
    $errors[] = 'permanent legacy ban should keep a NULL expiry';
}
if (is_readable($banFile)) {
    $errors[] = 'imported ban_list.json was not retired';
}
if (!is_readable($movedFile)) {
    $errors[] = 'ban_list.json.migrated was not created';
}

$scheduledAt = wp_next_scheduled(Cron::CLEANUP_HOOK);
if ($scheduledAt === false) {
    $errors[] = 'daily cleanup cron was not scheduled by the upgrade path';
}

/* ------------------------------------------------------------------
 * Idempotency: a second admin request must change nothing.
 * ----------------------------------------------------------------*/
$importCount = count($GLOBALS['wpdb']->replaced);
$stampBefore = $stored['internal']['migrated_v4'] ?? null;

Activator::maybeUpgrade();
Activator::maybeUpgrade();

if (count($GLOBALS['wpdb']->replaced) !== $importCount) {
    $errors[] = 'legacy import ran more than once';
}
$again = get_option(CF7SEC_OPTION);
if (($again['internal']['migrated_v4'] ?? null) !== $stampBefore) {
    $errors[] = 'migration marker changed on a repeated upgrade pass';
}
if (wp_next_scheduled(Cron::CLEANUP_HOOK) !== $scheduledAt) {
    $errors[] = 'cron was rescheduled instead of left alone';
}

/* Cleanup. */
@unlink($banFile);
@unlink($movedFile);
@rmdir($legacyDir);

if ($errors !== []) {
    fwrite(STDERR, "UPGRADE DEFECT (P1):\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}
echo "OK: plugin update imports v3.x data and schedules the cleanup cron (idempotent)\n";
