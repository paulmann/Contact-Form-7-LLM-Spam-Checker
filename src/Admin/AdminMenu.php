<?php
/**
 * Admin menu registration.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

use CF7Sec\Plugin;

defined('ABSPATH') || exit;

final class AdminMenu
{
    public const CAPABILITY = 'manage_options';

    /** @var array<int, array{slug: string, title: string, view: string}> */
    public const PAGES = [
        ['slug' => 'cf7sec',              'title' => 'Dashboard',     'view' => 'dashboard'],
        ['slug' => 'cf7sec-settings',     'title' => 'Settings',      'view' => 'settings'],
        ['slug' => 'cf7sec-integrations', 'title' => 'Integrations',  'view' => 'integrations'],
        ['slug' => 'cf7sec-ip',           'title' => 'IP Rules',      'view' => 'ip-rules'],
        ['slug' => 'cf7sec-registry',     'title' => 'Spam Registry', 'view' => 'registry'],
        ['slug' => 'cf7sec-tools',        'title' => 'Tools',         'view' => 'tools'],
    ];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        $first = true;
        foreach (self::PAGES as $page) {
            $title = $this->translateTitle($page['title']);
            $cb    = fn() => PageRenderer::render($page['view'], $this->plugin, $page['slug']);

            if ($first) {
                add_menu_page(
                    $title . ' — Spam Checker Pro for Contact Form 7',
                    __('CF7 Security', 'spam-checker-for-contact-form-7'),
                    self::CAPABILITY,
                    $page['slug'],
                    $cb,
                    'dashicons-shield-alt',
                    26
                );
                // First submenu duplicates the parent — rename it.
                add_submenu_page($page['slug'], $title, $title, self::CAPABILITY, $page['slug'], $cb);
                $first = false;
                continue;
            }

            add_submenu_page(
                'cf7sec',
                $title . ' — Spam Checker Pro for Contact Form 7',
                $title,
                self::CAPABILITY,
                $page['slug'],
                $cb
            );
        }
    }

    private function translateTitle(string $title): string
    {
        return match ($title) {
            'Dashboard'     => __('Dashboard', 'spam-checker-for-contact-form-7'),
            'Settings'      => __('Settings', 'spam-checker-for-contact-form-7'),
            'Integrations'  => __('Integrations', 'spam-checker-for-contact-form-7'),
            'IP Rules'      => __('IP Rules', 'spam-checker-for-contact-form-7'),
            'Spam Registry' => __('Spam Registry', 'spam-checker-for-contact-form-7'),
            'Tools'         => __('Tools', 'spam-checker-for-contact-form-7'),
            default         => $title,
        };
    }

    public static function url(string $slug): string
    {
        return admin_url('admin.php?page=' . $slug);
    }
}
