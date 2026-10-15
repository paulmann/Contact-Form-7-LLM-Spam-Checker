<?php
/**
 * Spam registry — searchable log of every verdict.
 *
 * @package spam-checker-for-contact-form-7
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\Views\Ui;
use CF7Sec\Storage\EventsRepository;
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Spam Registry', 'spam-checker-for-contact-form-7') ?></h2>
        <p class="c-page-subtitle">
            <?= sprintf(
                /* translators: %s: total blocked entries */
                esc_html__('%s blocked entries stored. Retention is controlled in Settings → General.', 'spam-checker-for-contact-form-7'),
                '<strong>' . esc_html(number_format_i18n(EventsRepository::count(EventsRepository::VERDICT_BLOCKED))) . '</strong>'
            ) ?>
        </p>
    </div>
    <div class="c-page-actions">
        <button type="button" class="c-btn c-btn-outline" data-action="export-events"><?= Ui::icon('download', 'c-icon c-icon-sm') ?> <?= esc_html__('Export CSV', 'spam-checker-for-contact-form-7') ?></button>
        <button type="button" class="c-btn c-btn-ghost c-text-danger" data-action="clear-events"><?= Ui::icon('trash', 'c-icon c-icon-sm') ?> <?= esc_html__('Clear registry', 'spam-checker-for-contact-form-7') ?></button>
    </div>
</div>

<?= Ui::card(
    '',
    '',
    '<div class="c-filters" id="cf7sec-event-filters">'
    . '<select class="c-select c-select-sm" data-filter="verdict">'
    . '<option value="">' . esc_html__('All verdicts', 'spam-checker-for-contact-form-7') . '</option>'
    . '<option value="blocked">' . esc_html__('Blocked', 'spam-checker-for-contact-form-7') . '</option>'
    . '<option value="allowed">' . esc_html__('Allowed', 'spam-checker-for-contact-form-7') . '</option>'
    . '<option value="error">' . esc_html__('Integration errors', 'spam-checker-for-contact-form-7') . '</option>'
    . '<option value="info">' . esc_html__('Info (bans)', 'spam-checker-for-contact-form-7') . '</option>'
    . '</select>'
    . '<select class="c-select c-select-sm" data-filter="event_type">'
    . '<option value="">' . esc_html__('All types', 'spam-checker-for-contact-form-7') . '</option>'
    . '</select>'
    . '<input type="date" class="c-input c-input-sm" data-filter="from" aria-label="' . esc_attr__('From date', 'spam-checker-for-contact-form-7') . '">'
    . '<input type="date" class="c-input c-input-sm" data-filter="to" aria-label="' . esc_attr__('To date', 'spam-checker-for-contact-form-7') . '">'
    . '<div class="c-search c-search-grow">' . Ui::icon('search', 'c-icon c-icon-sm') . '<input type="search" class="c-input c-input-search c-input-sm" data-filter="search" placeholder="' . esc_attr__('IP, reason, payload…', 'spam-checker-for-contact-form-7') . '"></div>'
    . '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-action="apply-filters">' . esc_html__('Apply', 'spam-checker-for-contact-form-7') . '</button>'
    . '<button type="button" class="c-btn c-btn-ghost c-btn-sm" data-action="reset-filters">' . esc_html__('Reset', 'spam-checker-for-contact-form-7') . '</button>'
    . '</div>'
    . '<div id="cf7sec-events-table" class="c-table-wrap"><p class="c-muted">' . esc_html__('Loading…', 'spam-checker-for-contact-form-7') . '</p></div>'
    . '<div id="cf7sec-events-pager" class="c-pager"></div>'
) ?>
