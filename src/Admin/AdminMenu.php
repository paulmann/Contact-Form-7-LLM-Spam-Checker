<?php
/**
 * Admin menu registration.
 *
 * @package contact-form-7-spam-checker
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
                    $title . ' — CF7 Spam Checker Pro',
                    __('CF7 Security', 'contact-form-7-spam-checker'),
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
                $title . ' — CF7 Spam Checker Pro',
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
            'Dashboard'     => __('Dashboard', 'contact-form-7-spam-checker'),
            'Settings'      => __('Settings', 'contact-form-7-spam-checker'),
            'Integrations'  => __('Integrations', 'contact-form-7-spam-checker'),
            'IP Rules'      => __('IP Rules', 'contact-form-7-spam-checker'),
            'Spam Registry' => __('Spam Registry', 'contact-form-7-spam-checker'),
            'Tools'         => __('Tools', 'contact-form-7-spam-checker'),
            default         => $title,
        };
    }

    public static function url(string $slug): string
    {
        return admin_url('admin.php?page=' . $slug);
    }
}
