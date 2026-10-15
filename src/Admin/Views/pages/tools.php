<?php
/**
 * Tools page — backup, maintenance, danger zone.
 *
 * @package spam-checker-for-contact-form-7
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\Views\Ui;
use CF7Sec\Storage\GeoCache;

$s = $plugin->settings;
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Tools', 'spam-checker-for-contact-form-7') ?></h2>
        <p class="c-page-subtitle"><?= esc_html__('Backup, maintenance and emergency actions.', 'spam-checker-for-contact-form-7') ?></p>
    </div>
</div>

<div class="c-grid-2-top">

<?= Ui::card(
    __('Settings backup', 'spam-checker-for-contact-form-7'),
    __('Export the complete configuration (including API keys) or restore it on another site.', 'spam-checker-for-contact-form-7'),
    '<div class="c-btn-row">'
    . '<button type="button" class="c-btn c-btn-outline" data-action="export-settings">' . Ui::icon('download', 'c-icon c-icon-sm') . ' ' . esc_html__('Export JSON', 'spam-checker-for-contact-form-7') . '</button>'
    . '<label class="c-btn c-btn-outline c-btn-file">' . Ui::icon('upload', 'c-icon c-icon-sm') . ' ' . esc_html__('Import JSON', 'spam-checker-for-contact-form-7')
    . '<input type="file" id="cf7sec-import-file" accept=".json,application/json" hidden></label>'
    . '</div>'
) ?>

<?= Ui::card(
    __('Maintenance', 'spam-checker-for-contact-form-7'),
    __('The daily cron prunes expired bans, old events and stale caches. You can also run it manually.', 'spam-checker-for-contact-form-7'),
    '<div class="c-btn-row">'
    . '<button type="button" class="c-btn c-btn-outline" data-action="run-cleanup">' . Ui::icon('refresh', 'c-icon c-icon-sm') . ' ' . esc_html__('Run maintenance now', 'spam-checker-for-contact-form-7') . '</button>'
    . '<button type="button" class="c-btn c-btn-outline" data-action="clear-geo">' . Ui::icon('globe', 'c-icon c-icon-sm') . ' ' . esc_html__('Clear geo cache', 'spam-checker-for-contact-form-7') . ' (' . esc_html(number_format_i18n(GeoCache::count())) . ')</button>'
    . '<button type="button" class="c-btn c-btn-outline c-text-danger" data-action="reset-counters">' . Ui::icon('activity', 'c-icon c-icon-sm') . ' ' . esc_html__('Reset statistics', 'spam-checker-for-contact-form-7') . '</button>'
    . '</div>'
) ?>

<?= Ui::card(
    __('IP lookup', 'spam-checker-for-contact-form-7'),
    __('Query AbuseIPDB for any IP on demand (uses the configured API key and cache).', 'spam-checker-for-contact-form-7'),
    '<div class="c-inline-form c-inline-form-compact">'
    . '<input type="text" class="c-input" id="lookup-ip" placeholder="8.8.8.8" inputmode="numeric" autocomplete="off">'
    . '<button type="button" class="c-btn c-btn-primary" data-action="lookup-ip">' . Ui::icon('search', 'c-icon c-icon-sm') . ' ' . esc_html__('Lookup', 'spam-checker-for-contact-form-7') . '</button>'
    . '</div>'
    . '<div id="lookup-result" class="c-lookup-result"></div>'
) ?>

<?= Ui::card(
    __('Danger zone', 'spam-checker-for-contact-form-7'),
    __('Irreversible actions.', 'spam-checker-for-contact-form-7'),
    '<div class="c-btn-row">'
    . '<button type="button" class="c-btn c-btn-destructive" data-action="reset-settings">' . Ui::icon('alert', 'c-icon c-icon-sm') . ' ' . esc_html__('Reset ALL settings to defaults', 'spam-checker-for-contact-form-7') . '</button>'
    . '</div>'
    . '<p class="c-field-hint">' . esc_html__('Uninstall behaviour: the "Keep data on uninstall" switch lives in Settings → General. When off, deleting the plugin from WordPress removes all tables, options, transients and log files.', 'spam-checker-for-contact-form-7') . '</p>',
    '',
    'c-card-danger'
) ?>

</div>

<?= Ui::card(
    __('Requirements & environment', 'spam-checker-for-contact-form-7'),
    '',
    '<div id="cf7sec-health-full" class="c-health"><p class="c-muted">' . esc_html__('Loading…', 'spam-checker-for-contact-form-7') . '</p></div>'
) ?>
