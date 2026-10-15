<?php
/**
 * Dashboard page — KPI cards, 30-day chart, health, recent events.
 *
 * @package contact-form-7-spam-checker
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\AdminMenu;
use CF7Sec\Admin\Views\Ui;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\IpRuleRepository;

$series  = DailyStats::series(30);
$totals  = DailyStats::totals();
$todayP  = (int) ($series['processed'][count($series['processed']) - 1] ?? 0);
$todayB  = (int) ($series['blocked'][count($series['blocked']) - 1] ?? 0);
$bans    = IpRuleRepository::countActiveBans();
$wl      = IpRuleRepository::countWhitelist();
$blocked = EventsRepository::count(EventsRepository::VERDICT_BLOCKED);
$blockRate = $totals['processed'] > 0 ? round($totals['blocked'] / max(1, $totals['processed']) * 100, 1) : 0.0;
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Security Dashboard', 'contact-form-7-spam-checker') ?></h2>
        <p class="c-page-subtitle"><?= esc_html__('Live overview of form protection across your site.', 'contact-form-7-spam-checker') ?></p>
    </div>
    <div class="c-page-actions">
        <a class="c-btn c-btn-outline" href="<?= esc_url(AdminMenu::url('cf7sec-registry')) ?>"><?= esc_html__('Open registry', 'contact-form-7-spam-checker') ?></a>
        <button type="button" class="c-btn c-btn-ghost" data-action="refresh-dashboard"><?= Ui::icon('refresh', 'c-icon c-icon-sm') ?> <?= esc_html__('Refresh', 'contact-form-7-spam-checker') ?></button>
    </div>
</div>

<div class="c-kpi-grid">
    <?php
    $kpis = [
        ['label' => __('Submissions today', 'contact-form-7-spam-checker'), 'value' => $todayP, 'icon' => 'activity', 'tone' => 'c-kpi-default'],
        ['label' => __('Blocked today', 'contact-form-7-spam-checker'), 'value' => $todayB, 'icon' => 'shield', 'tone' => 'c-kpi-danger'],
        ['label' => __('Active bans', 'contact-form-7-spam-checker'), 'value' => $bans, 'icon' => 'ban', 'tone' => 'c-kpi-warning'],
        ['label' => __('Whitelisted IPs', 'contact-form-7-spam-checker'), 'value' => $wl, 'icon' => 'check', 'tone' => 'c-kpi-success'],
        ['label' => __('Registry (blocked)', 'contact-form-7-spam-checker'), 'value' => $blocked, 'icon' => 'list', 'tone' => 'c-kpi-default'],
        ['label' => __('Block rate (30 d)', 'contact-form-7-spam-checker'), 'value' => $blockRate . '%', 'icon' => 'zap', 'tone' => 'c-kpi-default'],
    ];
    foreach ($kpis as $kpi) :
        $display = is_int($kpi['value']) ? number_format_i18n($kpi['value']) : (string) $kpi['value'];
        ?>
        <div class="c-card c-kpi">
            <div class="c-kpi-icon <?= esc_attr($kpi['tone']) ?>"><?= Ui::icon($kpi['icon']) ?></div>
            <div class="c-kpi-body">
                <span class="c-kpi-value"><?= esc_html($display) ?></span>
                <span class="c-kpi-label"><?= esc_html($kpi['label']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="c-grid-main">
    <?= Ui::card(
        __('Traffic — last 30 days', 'contact-form-7-spam-checker'),
        __('Processed vs blocked submissions per day (UTC).', 'contact-form-7-spam-checker'),
        '<div id="cf7sec-chart" class="c-chart" role="img" aria-label="' . esc_attr__('30-day traffic chart', 'contact-form-7-spam-checker') . '"></div>
         <div class="c-legend"><span class="c-legend-item"><i class="c-dot c-dot-primary"></i>' . esc_html__('Processed', 'contact-form-7-spam-checker') . '</span>
         <span class="c-legend-item"><i class="c-dot c-dot-danger"></i>' . esc_html__('Blocked', 'contact-form-7-spam-checker') . '</span></div>'
    ) ?>

    <?= Ui::card(
        __('System health', 'contact-form-7-spam-checker'),
        '',
        '<div id="cf7sec-health" class="c-health"><p class="c-muted">' . esc_html__('Loading…', 'contact-form-7-spam-checker') . '</p></div>',
        '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-action="refresh-health">' . esc_html__('Re-check', 'contact-form-7-spam-checker') . '</button>'
    ) ?>
</div>

<?= Ui::card(
    __('Recent events', 'contact-form-7-spam-checker'),
    __('Latest registry entries — blocked spam, integration errors and bans.', 'contact-form-7-spam-checker'),
    '<div id="cf7sec-recent" class="c-table-wrap"><p class="c-muted">' . esc_html__('Loading…', 'contact-form-7-spam-checker') . '</p></div>',
    '<a class="c-btn c-btn-ghost c-btn-sm" href="' . esc_url(AdminMenu::url('cf7sec-registry')) . '">' . esc_html__('View all', 'contact-form-7-spam-checker') . ' →</a>'
) ?>
