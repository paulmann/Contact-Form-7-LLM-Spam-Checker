<?php
/**
 * Admin asset registration (all local — no CDNs).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

defined('ABSPATH') || exit;

final class Assets
{
    public const CSS_HANDLE = 'cf7sec-admin';
    public const JS_HANDLE  = 'cf7sec-admin';

    public function enqueue(string $hook): void
    {
        if (!str_contains($hook, 'cf7sec')) {
            return;
        }

        $cssFile = CF7SEC_DIR . 'assets/dist/admin.css';
        $jsFile  = CF7SEC_DIR . 'assets/dist/admin.js';

        wp_enqueue_style(
            self::CSS_HANDLE,
            CF7SEC_URL . 'assets/dist/admin.css',
            [],
            is_readable($cssFile) ? (string) filemtime($cssFile) : CF7SEC_VERSION
        );

        wp_enqueue_script(
            self::JS_HANDLE,
            CF7SEC_URL . 'assets/dist/admin.js',
            [],
            is_readable($jsFile) ? (string) filemtime($jsFile) : CF7SEC_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        // Theme bootstrap must run before first paint to avoid a flash.
        wp_add_inline_script(self::JS_HANDLE, self::themeBootstrap(), 'before');

        wp_localize_script(self::JS_HANDLE, 'CF7SEC_CONFIG', [
            'restUrl'  => esc_url_raw(rest_url(\CF7SEC_REST_NS)),
            'nonce'    => wp_create_nonce('wp_rest'),
            'adminUrl' => admin_url(),
            'version'  => CF7SEC_VERSION,
            'i18n'     => [
                'saved'          => __('Settings saved.', 'contact-form-7-spam-checker'),
                'saveError'      => __('Could not save settings.', 'contact-form-7-spam-checker'),
                'unsaved'        => __('You have unsaved changes.', 'contact-form-7-spam-checker'),
                'confirm'        => __('Are you sure?', 'contact-form-7-spam-checker'),
                'confirmBan'     => __('Ban this IP address?', 'contact-form-7-spam-checker'),
                'confirmUnban'   => __('Remove this IP rule?', 'contact-form-7-spam-checker'),
                'confirmClear'   => __('This permanently deletes data. Continue?', 'contact-form-7-spam-checker'),
                'copied'         => __('Copied to clipboard.', 'contact-form-7-spam-checker'),
                'testing'        => __('Testing…', 'contact-form-7-spam-checker'),
                'testOk'         => __('Connection OK.', 'contact-form-7-spam-checker'),
                'testFail'       => __('Connection failed.', 'contact-form-7-spam-checker'),
                'loading'        => __('Loading…', 'contact-form-7-spam-checker'),
                'empty'          => __('Nothing here yet.', 'contact-form-7-spam-checker'),
                'deleted'        => __('Deleted.', 'contact-form-7-spam-checker'),
                'banned'         => __('IP banned.', 'contact-form-7-spam-checker'),
                'unbanned'       => __('Rule removed.', 'contact-form-7-spam-checker'),
                'whitelisted'    => __('IP whitelisted.', 'contact-form-7-spam-checker'),
                'exportStarted'  => __('Export started.', 'contact-form-7-spam-checker'),
                'importOk'       => __('Settings imported.', 'contact-form-7-spam-checker'),
                'importFail'     => __('Import failed: invalid file.', 'contact-form-7-spam-checker'),
                'themeLight'     => __('Light', 'contact-form-7-spam-checker'),
                'themeDark'      => __('Dark', 'contact-form-7-spam-checker'),
                'themeSystem'    => __('System', 'contact-form-7-spam-checker'),
                'showKey'        => __('Show key', 'contact-form-7-spam-checker'),
                'hideKey'        => __('Hide key', 'contact-form-7-spam-checker'),
                'page'           => __('Page', 'contact-form-7-spam-checker'),
                'of'             => __('of', 'contact-form-7-spam-checker'),
                'selectAll'      => __('Select all', 'contact-form-7-spam-checker'),
                'selected'       => __('selected', 'contact-form-7-spam-checker'),
                'time'           => __('Time', 'contact-form-7-spam-checker'),
                'type'           => __('Type', 'contact-form-7-spam-checker'),
                'verdict'        => __('Verdict', 'contact-form-7-spam-checker'),
                'ip'             => __('IP', 'contact-form-7-spam-checker'),
                'reason'         => __('Reason', 'contact-form-7-spam-checker'),
                'source'         => __('Source', 'contact-form-7-spam-checker'),
                'strikes'        => __('Strikes', 'contact-form-7-spam-checker'),
                'created'        => __('Created', 'contact-form-7-spam-checker'),
                'expires'        => __('Expires', 'contact-form-7-spam-checker'),
                'details'        => __('Details', 'contact-form-7-spam-checker'),
                'items'          => __('items', 'contact-form-7-spam-checker'),
                'submission'     => __('Submission', 'contact-form-7-spam-checker'),
                'userAgent'      => __('User agent', 'contact-form-7-spam-checker'),
                'referrer'       => __('Referrer', 'contact-form-7-spam-checker'),
                'checkResults'   => __('Check results', 'contact-form-7-spam-checker'),
                'payload'        => __('Payload', 'contact-form-7-spam-checker'),
                'banIp'          => __('Ban IP', 'contact-form-7-spam-checker'),
            ],
            'pages'    => array_map(
                static fn(array $p): string => $p['slug'],
                AdminMenu::PAGES
            ),
        ]);
    }

    /**
     * Pre-paint theme bootstrap: applies the persisted theme (or the OS
     * preference on first visit) before CSS resolves, preventing a flash.
     */
    public static function themeBootstrap(): string
    {
        return <<<'JS'
(function () {
    try {
        var KEY = 'cf7sec-theme';
        var stored = localStorage.getItem(KEY);
        var theme = stored || 'system';
        var dark = theme === 'dark' || (theme === 'system' &&
            window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (dark) {
            document.documentElement.classList.add('cf7sec-dark');
        }
        document.documentElement.dataset.cf7secTheme = theme;
    } catch (e) { /* storage disabled — fall back to light */ }
})();
JS;
    }
}
