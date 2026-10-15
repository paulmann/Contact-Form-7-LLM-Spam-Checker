<?php
/**
 * Contact Form 7 editor integration panel.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

use CF7Sec\Plugin;
use CF7Sec\Storage\DailyStats;

defined('ABSPATH') || exit;

/**
 * Adds a "Spam Checker" panel to each contact form editor screen with the
 * protection status of that form and quick links.
 */
final class Cf7Panel
{
    public const PANEL_ID = 'cf7sec';

    /**
     * @param array<string, mixed> $panels
     *
     * @return array<string, mixed>
     */
    public static function register(array $panels): array
    {
        $panels[self::PANEL_ID] = [
            'title'    => __('Spam Checker', 'contact-form-7-spam-checker'),
            'callback' => [self::class, 'render'],
        ];

        return $panels;
    }

    public static function render(mixed $post = null): void
    {
        $plugin = Plugin::instance();
        $formId = is_object($post) && method_exists($post, 'id') ? (int) $post->id() : 0;

        $mode      = (string) $plugin->settings->get('general.protect_forms', 'all');
        $protected = $mode === 'all'
            || in_array($formId, array_map('intval', (array) $plugin->settings->get('general.form_ids', [])), true);

        $enabledChecks = [];
        foreach (array_keys((array) $plugin->settings->get('checks', [])) as $checkId) {
            if ($plugin->settings->isEnabled((string) $checkId)) {
                $enabledChecks[] = (string) $checkId;
            }
        }
        if ($plugin->settings->get('abuseipdb.enabled', false)) {
            $enabledChecks[] = 'abuseipdb';
        }
        if ($plugin->settings->get('llm.enabled', false)) {
            $enabledChecks[] = 'llm:' . $plugin->settings->get('llm.provider', 'deepseek');
        }

        $totals = DailyStats::totals();
        ?>
        <div class="cf7sec-panel" style="max-width:720px">
            <h2 style="margin-top:0"><?php esc_html_e('CF7 Spam Checker Pro', 'contact-form-7-spam-checker'); ?>
                <span style="font-weight:400;color:#64748b">v<?php echo esc_html(CF7SEC_VERSION); ?></span></h2>

            <p>
                <strong><?php esc_html_e('This form:', 'contact-form-7-spam-checker'); ?></strong>
                <?php if ($protected) : ?>
                    <span style="color:#16a34a">● <?php esc_html_e('PROTECTED', 'contact-form-7-spam-checker'); ?></span>
                <?php else : ?>
                    <span style="color:#dc2626">● <?php esc_html_e('NOT PROTECTED (excluded in Settings → General)', 'contact-form-7-spam-checker'); ?></span>
                <?php endif; ?>
            </p>

            <p>
                <strong><?php esc_html_e('Active layers:', 'contact-form-7-spam-checker'); ?></strong>
                <code style="font-size:12px"><?php echo esc_html(implode(', ', $enabledChecks) ?: '—'); ?></code>
            </p>

            <p>
                <?php
                printf(
                    /* translators: 1: processed count, 2: blocked count */
                    esc_html__('Site lifetime: %1$s submissions processed, %2$s blocked as spam.', 'contact-form-7-spam-checker'),
                    esc_html(number_format_i18n((int) $totals['processed'])),
                    esc_html(number_format_i18n((int) $totals['blocked']))
                );
                ?>
            </p>

            <p>
                <a class="button button-primary" href="<?php echo esc_url(AdminMenu::url('cf7sec')); ?>">
                    <?php esc_html_e('Open security dashboard', 'contact-form-7-spam-checker'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(AdminMenu::url('cf7sec-settings')); ?>">
                    <?php esc_html_e('Settings', 'contact-form-7-spam-checker'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(AdminMenu::url('cf7sec-registry')); ?>">
                    <?php esc_html_e('Spam registry', 'contact-form-7-spam-checker'); ?>
                </a>
            </p>

            <p style="color:#64748b;font-size:12px">
                <?php esc_html_e('No form tags are required — protection is applied automatically via hidden fields and the wpcf7_spam pipeline.', 'contact-form-7-spam-checker'); ?>
            </p>
        </div>
        <?php
    }
}
