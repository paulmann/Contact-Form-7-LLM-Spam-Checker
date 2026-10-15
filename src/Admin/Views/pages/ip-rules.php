<?php
/**
 * IP management — bans & whitelist.
 *
 * @package spam-checker-for-contact-form-7
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\Views\Ui;
use CF7Sec\Storage\IpRuleRepository;

$s = $plugin->settings;
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('IP Rules', 'spam-checker-for-contact-form-7') ?></h2>
        <p class="c-page-subtitle">
            <?= sprintf(
                /* translators: 1: active bans, 2: whitelisted */
                esc_html__('%1$s active bans · %2$s whitelisted IPs', 'spam-checker-for-contact-form-7'),
                '<strong>' . esc_html(number_format_i18n(IpRuleRepository::countActiveBans())) . '</strong>',
                '<strong>' . esc_html(number_format_i18n(IpRuleRepository::countWhitelist())) . '</strong>'
            ) ?>
        </p>
    </div>
    <div class="c-page-actions">
        <button type="button" class="c-btn c-btn-outline" data-action="export-ip-rules"><?= Ui::icon('download', 'c-icon c-icon-sm') ?> <?= esc_html__('Export CSV', 'spam-checker-for-contact-form-7') ?></button>
        <button type="button" class="c-btn c-btn-ghost c-text-danger" data-action="bulk-delete-rules"><?= Ui::icon('trash', 'c-icon c-icon-sm') ?> <?= esc_html__('Delete selected', 'spam-checker-for-contact-form-7') ?></button>
    </div>
</div>

<div class="c-tabs" role="tablist" data-tabs>
    <button type="button" role="tab" class="c-tab c-tab-active" data-ip-tab="ban" aria-selected="true"><?= esc_html__('Ban list', 'spam-checker-for-contact-form-7') ?></button>
    <button type="button" role="tab" class="c-tab" data-ip-tab="whitelist" aria-selected="false"><?= esc_html__('Whitelist', 'spam-checker-for-contact-form-7') ?></button>
</div>

<?= Ui::card(
    __('Add rule', 'spam-checker-for-contact-form-7'),
    '',
    '<div class="c-inline-form" id="cf7sec-add-rule">'
    . '<div class="c-field"><span class="c-field-label">' . esc_html__('IP address', 'spam-checker-for-contact-form-7') . '</span><input type="text" class="c-input" id="rule-ip" placeholder="203.0.113.42" inputmode="numeric" autocomplete="off"></div>'
    . '<div class="c-field"><span class="c-field-label">' . esc_html__('Type', 'spam-checker-for-contact-form-7') . '</span><select class="c-select" id="rule-type">'
    . '<option value="ban">' . esc_html__('Ban', 'spam-checker-for-contact-form-7') . '</option>'
    . '<option value="whitelist">' . esc_html__('Whitelist (never block)', 'spam-checker-for-contact-form-7') . '</option></select></div>'
    . '<div class="c-field"><span class="c-field-label">' . esc_html__('Duration (hours, 0 = permanent)', 'spam-checker-for-contact-form-7') . '</span><input type="number" class="c-input" id="rule-duration" value="' . esc_attr((string) (int) $s->get('checks.rate_limit.ban_duration_hours', 1)) . '" min="0" max="8760"></div>'
    . '<div class="c-field c-field-grow"><span class="c-field-label">' . esc_html__('Reason', 'spam-checker-for-contact-form-7') . '</span><input type="text" class="c-input" id="rule-reason" placeholder="' . esc_attr__('Manual ban', 'spam-checker-for-contact-form-7') . '"></div>'
    . '<div class="c-field c-field-btn"><button type="button" class="c-btn c-btn-primary" data-action="add-rule">' . Ui::icon('plus', 'c-icon c-icon-sm') . ' ' . esc_html__('Add', 'spam-checker-for-contact-form-7') . '</button></div>'
    . '</div>'
) ?>

<?= Ui::card(
    '',
    '',
    '<div class="c-table-toolbar">'
    . '<div class="c-search">' . Ui::icon('search', 'c-icon c-icon-sm') . '<input type="search" id="cf7sec-rule-search" class="c-input c-input-search" placeholder="' . esc_attr__('Search IP or reason…', 'spam-checker-for-contact-form-7') . '"></div>'
    . '<span id="cf7sec-rule-count" class="c-muted c-hint-sm"></span>'
    . '</div>'
    . '<div id="cf7sec-ip-table" class="c-table-wrap"><p class="c-muted">' . esc_html__('Loading…', 'spam-checker-for-contact-form-7') . '</p></div>'
    . '<div id="cf7sec-ip-pager" class="c-pager"></div>'
) ?>
