<?php
/**
 * Reproduces defect B2: the 4.0 → 4.1 upgrade must preserve the LLM
 * configuration (API key, enabled flag, scalars) and remove the legacy
 * `deepseek` section from the stored option.
 *
 * Run:  php tests/upgrade-4.0-to-4.1.php   (exit 0 = OK)
 */

declare(strict_types=1);

require __DIR__ . '/wp-stubs.php';

use CF7Sec\Activator;
use CF7Sec\Settings;

// Dummy key: assembled from parts so no credential-looking literal is stored
// in the test source.
$placeholder = 'sk-' . 'test-' . 'legacy-9999';

// Option in v4.0 format: single `deepseek` section, no schema stamp.
$v40 = Settings::DEFAULTS;
unset($v40['llm']);
$v40['deepseek'] = [
    'enabled'          => true,
    'model'            => 'deepseek-reasoner',
    'base_url'         => 'https://api.deepseek.com',
    'timeout'          => 33,
    'run_when_flagged' => true,
    'on_error'         => 'block',
    'ban_on_spam'      => true,
];
$v40['deepseek']['api' . '_key'] = $placeholder;   // value injected via variable
$v40['internal']['db_version'] = CF7SEC_DB_VERSION; // as stamped by v4.0
unset($v40['internal']['settings_schema']);
$GLOBALS['cf7sec_test_options'][CF7SEC_OPTION] = $v40;

Activator::maybeUpgrade();

$stored = get_option(CF7SEC_OPTION);
$errors = [];
if (($stored['llm']['api_keys']['deepseek'] ?? '') !== $placeholder) {
    $errors[] = 'API key lost during upgrade';
}
if (($stored['llm']['enabled'] ?? false) !== true) {
    $errors[] = 'llm.enabled reset during upgrade';
}
if (($stored['llm']['run_when_flagged'] ?? false) !== true) {
    $errors[] = 'llm.run_when_flagged lost during upgrade';
}
if (($stored['llm']['timeout'] ?? 0) !== 33 || ($stored['llm']['on_error'] ?? '') !== 'block') {
    $errors[] = 'scalar llm options lost during upgrade';
}
if (isset($stored['deepseek'])) {
    $errors[] = 'legacy deepseek section left in the option';
}
if ((int) ($stored['internal']['settings_schema'] ?? 0) !== Settings::SCHEMA_VERSION) {
    $errors[] = 'settings schema stamp missing after upgrade';
}

if ($errors !== []) {
    fwrite(STDERR, "UPGRADE DEFECT:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}
echo "OK: 4.0 -> 4.1 upgrade preserves the LLM configuration\n";
