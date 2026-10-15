<?php
/**
 * Contact Form 7 editor integration panel.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

use CF7Sec\Plugin;
use CF7Sec\Storage\DailyStats;

defined('ABSPATH') || exit;

/**
 * Adds a "Spam Checker" panel to each contact form editor screen with the
 * protection status of that form and quick links. Styled with the shared
 * design system (the stylesheet is enqueued on CF7 editor hooks as well),
 * so it follows the light/dark theme like the rest of the admin UI.
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
            'title'    => __('Spam Checker', 'spam-checker-for-contact-form-7'),
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
            $enabledChecks[] = 'llm:' . (string) $plugin->settings->get('llm.provider', 'deepseek');
        }

        $totals = DailyStats::totals();
        ?>
        <div class="cf7sec-panel">
            <div class="c-card">
                <div class="c-card-header">
                    <h3 class="c-card-title">
                        <?php esc_html_e('Spam Checker Pro for Contact Form 7', 'spam-checker-for-contact-form-7'); ?>
                        <span class="c-badge c-badge-outline">v<?php echo esc_html(CF7SEC_VERSION); ?></span>
                    </h3>
                    <p class="c-card-desc"><?php esc_html_e('No form tags are required — protection is applied automatically via hidden fields and the wpcf7_spam pipeline.', 'spam-checker-for-contact-form-7'); ?></p>
                </div>
                <div class="c-card-body">
                    <div class="c-field">
                        <span class="c-field-label"><?php esc_html_e('This form:', 'spam-checker-for-contact-form-7'); ?></span>
                        <span>
                            <?php if ($protected) : ?>
                                <span class="c-badge c-badge-success"><?php esc_html_e('PROTECTED', 'spam-checker-for-contact-form-7'); ?></span>
                            <?php else : ?>
                                <span class="c-badge c-badge-destructive"><?php esc_html_e('NOT PROTECTED (excluded in Settings → General)', 'spam-checker-for-contact-form-7'); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="c-field">
                        <span class="c-field-label"><?php esc_html_e('Active layers:', 'spam-checker-for-contact-form-7'); ?></span>
                        <code><?php echo esc_html(implode(', ', $enabledChecks) ?: '—'); ?></code>
                    </div>

                    <p class="c-muted">
                        <?php
                        printf(
                            /* translators: 1: processed count, 2: blocked count */
                            esc_html__('Site lifetime: %1$s submissions processed, %2$s blocked as spam.', 'spam-checker-for-contact-form-7'),
                            esc_html(number_format_i18n((int) $totals['processed'])),
                            esc_html(number_format_i18n((int) $totals['blocked']))
                        );
                        ?>
                    </p>

                    <div class="c-btn-row">
                        <a class="c-btn c-btn-primary" href="<?php echo esc_url(AdminMenu::url('cf7sec')); ?>">
                            <?php esc_html_e('Open security dashboard', 'spam-checker-for-contact-form-7'); ?>
                        </a>
                        <a class="c-btn c-btn-outline" href="<?php echo esc_url(AdminMenu::url('cf7sec-settings')); ?>">
                            <?php esc_html_e('Settings', 'spam-checker-for-contact-form-7'); ?>
                        </a>
                        <a class="c-btn c-btn-outline" href="<?php echo esc_url(AdminMenu::url('cf7sec-registry')); ?>">
                            <?php esc_html_e('Spam registry', 'spam-checker-for-contact-form-7'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
