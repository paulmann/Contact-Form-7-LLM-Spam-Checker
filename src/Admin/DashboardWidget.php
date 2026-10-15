<?php
/**
 * WordPress dashboard widget with a compact protection summary.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

use CF7Sec\Plugin;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\IpRuleRepository;

defined('ABSPATH') || exit;

final class DashboardWidget
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        if (!$this->plugin->settings->get('general.show_admin_widget', true)) {
            return;
        }
        if (!current_user_can(AdminMenu::CAPABILITY)) {
            return;
        }

        wp_add_dashboard_widget(
            'cf7sec_widget',
            __('CF7 Spam Checker — today', 'spam-checker-for-contact-form-7'),
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $processed = DailyStats::series(1);
        $bans      = IpRuleRepository::countActiveBans();
        $registry  = EventsRepository::count(EventsRepository::VERDICT_BLOCKED);

        $rows = [
            [__('Submissions today', 'spam-checker-for-contact-form-7'), (int) ($processed['processed'][0] ?? 0)],
            [__('Blocked today', 'spam-checker-for-contact-form-7'), (int) ($processed['blocked'][0] ?? 0)],
            [__('Active bans', 'spam-checker-for-contact-form-7'), $bans],
            [__('Registry entries', 'spam-checker-for-contact-form-7'), $registry],
        ];

        echo '<table class="widefat striped" style="border:0"><tbody>';
        foreach ($rows as [$label, $value]) {
            printf(
                '<tr><td>%s</td><td style="text-align:right"><strong>%s</strong></td></tr>',
                esc_html($label),
                esc_html(number_format_i18n($value))
            );
        }
        echo '</tbody></table>';

        printf(
            '<p style="margin:12px 0 0"><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
            esc_url(AdminMenu::url('cf7sec')),
            esc_html__('Open dashboard', 'spam-checker-for-contact-form-7'),
            esc_url(AdminMenu::url('cf7sec-registry')),
            esc_html__('Spam registry', 'spam-checker-for-contact-form-7')
        );
    }
}
