<?php
/**
 * Tools page — backup, maintenance, danger zone.
 *
 * @package contact-form-7-spam-checker
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
        <h2 class="c-page-title"><?= esc_html__('Tools', 'contact-form-7-spam-checker') ?></h2>
        <p class="c-page-subtitle"><?= esc_html__('Backup, maintenance and emergency actions.', 'contact-form-7-spam-checker') ?></p>
    </div>
</div>

<div class="c-grid-2-top">

<?= Ui::card(
    __('Settings backup', 'contact-form-7-spam-checker'),
    __('Export the complete configuration (including API keys) or restore it on another site.', 'contact-form-7-spam-checker'),
    '<div class="c-btn-row">'
    . '<button type="button" class="c-btn c-btn-outline" data-action="export-settings">' . Ui::icon('download', 'c-icon c-icon-sm') . ' ' . esc_html__('Export JSON', 'contact-form-7-spam-checker') . '</button>'
    . '<label class="c-btn c-btn-outline c-btn-file">' . Ui::icon('upload', 'c-icon c-icon-sm') . ' ' . esc_html__('Import JSON', 'contact-form-7-spam-checker')
    . '<input type="file" id="cf7sec-import-file" accept=".json,application/json" hidden></label>'
    . '</div>'
) ?>

<?= Ui::card(
    __('Maintenance', 'contact-form-7-spam-checker'),
    __('The daily cron prunes expired bans, old events and stale caches. You can also run it manually.', 'contact-form-7-spam-checker'),
    '<div class="c-btn-row">'
    . '<button type="button" class="c-btn c-btn-outline" data-action="run-cleanup">' . Ui::icon('refresh', 'c-icon c-icon-sm') . ' ' . esc_html__('Run maintenance now', 'contact-form-7-spam-checker') . '</button>'
    . '<button type="button" class="c-btn c-btn-outline" data-action="clear-geo">' . Ui::icon('globe', 'c-icon c-icon-sm') . ' ' . esc_html__('Clear geo cache', 'contact-form-7-spam-checker') . ' (' . esc_html(number_format_i18n(GeoCache::count())) . ')</button>'
    . '<button type="button" class="c-btn c-btn-outline c-text-danger" data-action="reset-counters">' . Ui::icon('activity', 'c-icon c-icon-sm') . ' ' . esc_html__('Reset statistics', 'contact-form-7-spam-checker') . '</button>'
    . '</div>'
) ?>

<?= Ui::card(
    __('IP lookup', 'contact-form-7-spam-checker'),
    __('Query AbuseIPDB for any IP on demand (uses the configured API key and cache).', 'contact-form-7-spam-checker'),
    '<div class="c-inline-form c-inline-form-compact">'
    . '<input type="text" class="c-input" id="lookup-ip" placeholder="8.8.8.8" inputmode="numeric" autocomplete="off">'
    . '<button type="button" class="c-btn c-btn-primary" data-action="lookup-ip">' . Ui::icon('search', 'c-icon c-icon-sm') . ' ' . esc_html__('Lookup', 'contact-form-7-spam-checker') . '</button>'
    . '</div>'
    . '<div id="lookup-result" class="c-lookup-result"></div>'
) ?>

<?= Ui::card(
    __('Danger zone', 'contact-form-7-spam-checker'),
    __('Irreversible actions.', 'contact-form-7-spam-checker'),
    '<div class="c-btn-row">'
    . '<button type="button" class="c-btn c-btn-destructive" data-action="reset-settings">' . Ui::icon('alert', 'c-icon c-icon-sm') . ' ' . esc_html__('Reset ALL settings to defaults', 'contact-form-7-spam-checker') . '</button>'
    . '</div>'
    . '<p class="c-field-hint">' . esc_html__('Uninstall behaviour: the "Keep data on uninstall" switch lives in Settings → General. When off, deleting the plugin from WordPress removes all tables, options, transients and log files.', 'contact-form-7-spam-checker') . '</p>',
    '',
    'c-card-danger'
) ?>

</div>

<?= Ui::card(
    __('Requirements & environment', 'contact-form-7-spam-checker'),
    '',
    '<div id="cf7sec-health-full" class="c-health"><p class="c-muted">' . esc_html__('Loading…', 'contact-form-7-spam-checker') . '</p></div>'
) ?>
