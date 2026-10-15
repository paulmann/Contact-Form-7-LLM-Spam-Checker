<?php
/**
 * Renders the admin app shell (header, nav, footer) around page views.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

use CF7Sec\Plugin;

defined('ABSPATH') || exit;

final class PageRenderer
{
    public static function render(string $view, Plugin $plugin, string $currentSlug): void
    {
        $cf7Active = $plugin->cf7Active();
        $navItems  = self::navItems();

        echo '<div id="cf7sec-app" class="cf7sec-app" data-slug="' . esc_attr($currentSlug) . '">';

        /* ---------- header ---------- */
        echo '<header class="c-topbar">';
        echo '<div class="c-topbar-brand">';
        echo '<span class="c-logo">' . Views\Ui::icon('shield') . '</span>';
        echo '<div class="c-brand-text"><span class="c-brand-name">' . esc_html__('CF7 Spam Checker', 'spam-checker-for-contact-form-7') . '</span>';
        echo '<span class="c-brand-sub">' . esc_html__('Advanced form security', 'spam-checker-for-contact-form-7') . '</span></div>';
        echo Views\Ui::badge('v' . CF7SEC_VERSION, 'outline');
        echo $cf7Active
            ? Views\Ui::badge(__('CF7 active', 'spam-checker-for-contact-form-7'), 'success')
            : Views\Ui::badge(__('CF7 missing', 'spam-checker-for-contact-form-7'), 'destructive');
        echo '</div>';

        echo '<div class="c-topbar-actions">';
        echo '<button type="button" id="cf7sec-save" class="c-btn c-btn-primary c-save-btn" hidden>'
            . Views\Ui::icon('check', 'c-icon c-icon-sm') . '<span>' . esc_html__('Save changes', 'spam-checker-for-contact-form-7') . '</span></button>';
        echo '<button type="button" id="cf7sec-theme-toggle" class="c-btn c-btn-ghost c-btn-icon" '
            . 'aria-label="' . esc_attr__('Toggle theme', 'spam-checker-for-contact-form-7') . '" title="' . esc_attr__('Theme: light / dark / system', 'spam-checker-for-contact-form-7') . '">'
            . '<span class="c-theme-icon" data-theme-icon="light">' . Views\Ui::icon('sun') . '</span>'
            . '<span class="c-theme-icon" data-theme-icon="dark">' . Views\Ui::icon('moon') . '</span>'
            . '<span class="c-theme-icon" data-theme-icon="system">' . Views\Ui::icon('monitor') . '</span>'
            . '</button>';
        echo '</div>';
        echo '</header>';

        /* ---------- nav ---------- */
        echo '<nav class="c-nav" aria-label="' . esc_attr__('Plugin sections', 'spam-checker-for-contact-form-7') . '">';
        foreach ($navItems as $item) {
            $active = $item['slug'] === $currentSlug ? ' c-nav-active' : '';
            printf(
                '<a class="c-nav-item%s" href="%s">%s<span>%s</span></a>',
                esc_attr($active),
                esc_url($item['url']),
                $item['icon'],
                esc_html($item['title'])
            );
        }
        echo '</nav>';

        /* ---------- page ---------- */
        echo '<main class="c-main">';

        $viewFile = __DIR__ . '/Views/pages/' . $view . '.php';
        if (is_readable($viewFile)) {
            include $viewFile;
        } else {
            echo '<p>' . esc_html__('Unknown page.', 'spam-checker-for-contact-form-7') . '</p>';
        }

        echo '</main>';

        /* ---------- footer ---------- */
        echo '<footer class="c-footer">';
        printf(
            /* translators: 1: version, 2: PHP version */
            esc_html__('Spam Checker Pro for Contact Form 7 %1$s · PHP %2$s · ', 'spam-checker-for-contact-form-7'),
            esc_html(CF7SEC_VERSION),
            esc_html(PHP_VERSION)
        );
        echo '<a href="https://github.com/paulmann/Contact-Form-7-LLM-Spam-Checker" target="_blank" rel="noopener">GitHub</a> · ';
        echo '<a href="https://deynekin.com" target="_blank" rel="noopener">deynekin.com</a>';
        echo '</footer>';

        echo '</div>'; // #cf7sec-app

        /* ---------- overlays ---------- */
        echo '<div id="cf7sec-toasts" class="c-toasts" aria-live="polite"></div>';
        echo '<div id="cf7sec-modal-root"></div>';
    }

    /**
     * @return array<int, array{slug: string, title: string, url: string, icon: string}>
     */
    private static function navItems(): array
    {
        return [
            ['slug' => 'cf7sec',              'title' => __('Dashboard', 'spam-checker-for-contact-form-7'),     'url' => AdminMenu::url('cf7sec'),              'icon' => Views\Ui::icon('activity')],
            ['slug' => 'cf7sec-settings',     'title' => __('Settings', 'spam-checker-for-contact-form-7'),      'url' => AdminMenu::url('cf7sec-settings'),     'icon' => Views\Ui::icon('sliders')],
            ['slug' => 'cf7sec-integrations', 'title' => __('Integrations', 'spam-checker-for-contact-form-7'),  'url' => AdminMenu::url('cf7sec-integrations'), 'icon' => Views\Ui::icon('zap')],
            ['slug' => 'cf7sec-ip',           'title' => __('IP Rules', 'spam-checker-for-contact-form-7'),      'url' => AdminMenu::url('cf7sec-ip'),           'icon' => Views\Ui::icon('ban')],
            ['slug' => 'cf7sec-registry',     'title' => __('Spam Registry', 'spam-checker-for-contact-form-7'), 'url' => AdminMenu::url('cf7sec-registry'),     'icon' => Views\Ui::icon('list')],
            ['slug' => 'cf7sec-tools',        'title' => __('Tools', 'spam-checker-for-contact-form-7'),         'url' => AdminMenu::url('cf7sec-tools'),        'icon' => Views\Ui::icon('cpu')],
        ];
    }
}
