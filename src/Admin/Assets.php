<?php
/**
 * Admin asset registration (all local — no CDNs).
 *
 * @package spam-checker-for-contact-form-7
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
        // CF7 editor screens get the stylesheet + theme bootstrap as well:
        // the "Spam Checker" panel is styled with the same design system.
        if (!str_contains($hook, 'cf7sec') && !str_contains($hook, 'wpcf7')) {
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
                'saved'          => __('Settings saved.', 'spam-checker-for-contact-form-7'),
                'saveError'      => __('Could not save settings.', 'spam-checker-for-contact-form-7'),
                'unsaved'        => __('You have unsaved changes.', 'spam-checker-for-contact-form-7'),
                'confirm'        => __('Are you sure?', 'spam-checker-for-contact-form-7'),
                'confirmBan'     => __('Ban this IP address?', 'spam-checker-for-contact-form-7'),
                'confirmUnban'   => __('Remove this IP rule?', 'spam-checker-for-contact-form-7'),
                'confirmClear'   => __('This permanently deletes data. Continue?', 'spam-checker-for-contact-form-7'),
                'copied'         => __('Copied to clipboard.', 'spam-checker-for-contact-form-7'),
                'testing'        => __('Testing…', 'spam-checker-for-contact-form-7'),
                'testOk'         => __('Connection OK.', 'spam-checker-for-contact-form-7'),
                'testFail'       => __('Connection failed.', 'spam-checker-for-contact-form-7'),
                'loading'        => __('Loading…', 'spam-checker-for-contact-form-7'),
                'empty'          => __('Nothing here yet.', 'spam-checker-for-contact-form-7'),
                'deleted'        => __('Deleted.', 'spam-checker-for-contact-form-7'),
                'banned'         => __('IP banned.', 'spam-checker-for-contact-form-7'),
                'unbanned'       => __('Rule removed.', 'spam-checker-for-contact-form-7'),
                'whitelisted'    => __('IP whitelisted.', 'spam-checker-for-contact-form-7'),
                'exportStarted'  => __('Export started.', 'spam-checker-for-contact-form-7'),
                'importOk'       => __('Settings imported.', 'spam-checker-for-contact-form-7'),
                'importFail'     => __('Import failed: invalid file.', 'spam-checker-for-contact-form-7'),
                'themeLight'     => __('Light', 'spam-checker-for-contact-form-7'),
                'themeDark'      => __('Dark', 'spam-checker-for-contact-form-7'),
                'themeSystem'    => __('System', 'spam-checker-for-contact-form-7'),
                'showKey'        => __('Show key', 'spam-checker-for-contact-form-7'),
                'hideKey'        => __('Hide key', 'spam-checker-for-contact-form-7'),
                'page'           => __('Page', 'spam-checker-for-contact-form-7'),
                'of'             => __('of', 'spam-checker-for-contact-form-7'),
                'selectAll'      => __('Select all', 'spam-checker-for-contact-form-7'),
                'selected'       => __('selected', 'spam-checker-for-contact-form-7'),
                'time'           => __('Time', 'spam-checker-for-contact-form-7'),
                'type'           => __('Type', 'spam-checker-for-contact-form-7'),
                'verdict'        => __('Verdict', 'spam-checker-for-contact-form-7'),
                'ip'             => __('IP', 'spam-checker-for-contact-form-7'),
                'reason'         => __('Reason', 'spam-checker-for-contact-form-7'),
                'source'         => __('Source', 'spam-checker-for-contact-form-7'),
                'strikes'        => __('Strikes', 'spam-checker-for-contact-form-7'),
                'created'        => __('Created', 'spam-checker-for-contact-form-7'),
                'expires'        => __('Expires', 'spam-checker-for-contact-form-7'),
                'details'        => __('Details', 'spam-checker-for-contact-form-7'),
                'items'          => __('items', 'spam-checker-for-contact-form-7'),
                'bans'           => __('bans', 'spam-checker-for-contact-form-7'),
                'deleteAll'      => __('Delete all', 'spam-checker-for-contact-form-7'),
                'remove'         => __('Remove', 'spam-checker-for-contact-form-7'),
                'ipRequired'     => __('IP is required', 'spam-checker-for-contact-form-7'),
                'resetConfirm'   => __('Reset every option to factory defaults? API keys will be erased.', 'spam-checker-for-contact-form-7'),
                'resetEverything' => __('Reset everything', 'spam-checker-for-contact-form-7'),
                'submission'     => __('Submission', 'spam-checker-for-contact-form-7'),
                'userAgent'      => __('User agent', 'spam-checker-for-contact-form-7'),
                'referrer'       => __('Referrer', 'spam-checker-for-contact-form-7'),
                'checkResults'   => __('Check results', 'spam-checker-for-contact-form-7'),
                'payload'        => __('Payload', 'spam-checker-for-contact-form-7'),
                'banIp'          => __('Ban IP', 'spam-checker-for-contact-form-7'),
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
