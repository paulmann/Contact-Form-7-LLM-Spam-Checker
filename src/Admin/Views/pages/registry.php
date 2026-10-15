<?php
/**
 * Spam registry — searchable log of every verdict.
 *
 * @package contact-form-7-spam-checker
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\Views\Ui;
use CF7Sec\Storage\EventsRepository;
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Spam Registry', 'contact-form-7-spam-checker') ?></h2>
        <p class="c-page-subtitle">
            <?= sprintf(
                /* translators: %s: total blocked entries */
                esc_html__('%s blocked entries stored. Retention is controlled in Settings → General.', 'contact-form-7-spam-checker'),
                '<strong>' . esc_html(number_format_i18n(EventsRepository::count(EventsRepository::VERDICT_BLOCKED))) . '</strong>'
            ) ?>
        </p>
    </div>
    <div class="c-page-actions">
        <button type="button" class="c-btn c-btn-outline" data-action="export-events"><?= Ui::icon('download', 'c-icon c-icon-sm') ?> <?= esc_html__('Export CSV', 'contact-form-7-spam-checker') ?></button>
        <button type="button" class="c-btn c-btn-ghost c-text-danger" data-action="clear-events"><?= Ui::icon('trash', 'c-icon c-icon-sm') ?> <?= esc_html__('Clear registry', 'contact-form-7-spam-checker') ?></button>
    </div>
</div>

<?= Ui::card(
    '',
    '',
    '<div class="c-filters" id="cf7sec-event-filters">'
    . '<select class="c-select c-select-sm" data-filter="verdict">'
    . '<option value="">' . esc_html__('All verdicts', 'contact-form-7-spam-checker') . '</option>'
    . '<option value="blocked">' . esc_html__('Blocked', 'contact-form-7-spam-checker') . '</option>'
    . '<option value="allowed">' . esc_html__('Allowed', 'contact-form-7-spam-checker') . '</option>'
    . '<option value="error">' . esc_html__('Integration errors', 'contact-form-7-spam-checker') . '</option>'
    . '<option value="info">' . esc_html__('Info (bans)', 'contact-form-7-spam-checker') . '</option>'
    . '</select>'
    . '<select class="c-select c-select-sm" data-filter="event_type">'
    . '<option value="">' . esc_html__('All types', 'contact-form-7-spam-checker') . '</option>'
    . '</select>'
    . '<input type="date" class="c-input c-input-sm" data-filter="from" aria-label="' . esc_attr__('From date', 'contact-form-7-spam-checker') . '">'
    . '<input type="date" class="c-input c-input-sm" data-filter="to" aria-label="' . esc_attr__('To date', 'contact-form-7-spam-checker') . '">'
    . '<div class="c-search c-search-grow">' . Ui::icon('search', 'c-icon c-icon-sm') . '<input type="search" class="c-input c-input-search c-input-sm" data-filter="search" placeholder="' . esc_attr__('IP, reason, payload…', 'contact-form-7-spam-checker') . '"></div>'
    . '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-action="apply-filters">' . esc_html__('Apply', 'contact-form-7-spam-checker') . '</button>'
    . '<button type="button" class="c-btn c-btn-ghost c-btn-sm" data-action="reset-filters">' . esc_html__('Reset', 'contact-form-7-spam-checker') . '</button>'
    . '</div>'
    . '<div id="cf7sec-events-table" class="c-table-wrap"><p class="c-muted">' . esc_html__('Loading…', 'contact-form-7-spam-checker') . '</p></div>'
    . '<div id="cf7sec-events-pager" class="c-pager"></div>'
) ?>
