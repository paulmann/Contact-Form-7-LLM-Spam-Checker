<?php
/**
 * Plugin orchestrator — service container + hook registration.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec;

use CF7Sec\Admin\AdminMenu;
use CF7Sec\Admin\Assets;
use CF7Sec\Admin\Cf7Panel;
use CF7Sec\Admin\DashboardWidget;
use CF7Sec\Admin\RestController;
use CF7Sec\Security\Checks\HoneypotCheck;
use CF7Sec\Security\Checks\TimeCheck;
use CF7Sec\Security\LanguageValidator;
use CF7Sec\Security\SpamPipeline;
use CF7Sec\Services\AbuseIpDb;
use CF7Sec\Services\BanManager;
use CF7Sec\Services\Llm\LlmGateway;
use CF7Sec\Services\Logger;
use CF7Sec\Services\Mailer;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\IpRuleRepository;
use CF7Sec\Support\Ip;

defined('ABSPATH') || exit;

/**
 * Composition root. All services are created once, lazily, and shared.
 */
final class Plugin
{
    private static ?self $instance = null;

    public readonly Settings $settings;
    public readonly Logger $logger;
    public readonly Mailer $mailer;
    public readonly BanManager $bans;
    public readonly LlmGateway $llm;
    public readonly AbuseIpDb $abuseipdb;
    public readonly SpamPipeline $pipeline;
    public readonly LanguageValidator $language;

    private bool $booted = false;

    private function __construct()
    {
        $this->settings   = new Settings();
        $this->logger     = new Logger($this->settings);
        $this->mailer     = new Mailer($this->settings);
        $this->bans       = new BanManager($this->settings, $this->mailer);
        $this->llm        = new LlmGateway($this->settings);
        $this->abuseipdb  = new AbuseIpDb($this->settings);
        $this->pipeline   = new SpamPipeline(
            $this->settings,
            $this->bans,
            $this->mailer,
            $this->logger,
            $this->abuseipdb,
            $this->llm,
        );
        $this->language   = new LanguageValidator($this->settings, $this->logger);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Register all WordPress hooks. Idempotent.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        /* ---- i18n & maintenance ---- */
        add_action('init', [$this, 'loadTranslations']);
        Cron::register();
        add_action('admin_init', [Activator::class, 'maybeUpgrade']);

        /* ---- Contact Form 7 integration ---- */
        if ($this->cf7Active()) {
            add_filter('wpcf7_spam', [$this->pipeline, 'filter'], 5, 2);
            add_filter('wpcf7_form_hidden_fields', [$this, 'addHiddenFields']);
            add_filter('wpcf7_display_message', [$this, 'filterSpamMessage'], 10, 2);
            add_action('wpcf7_enqueue_scripts', [$this, 'enqueueFrontendScript']);

            foreach (['text', 'text*', 'email', 'email*', 'tel', 'tel*', 'textarea', 'textarea*'] as $type) {
                add_filter("wpcf7_validate_{$type}", [$this->language, 'validate'], 10, 2);
            }

            add_filter('wpcf7_editor_panels', [Cf7Panel::class, 'register']);
            add_action('wpcf7_editor_panel_cf7sec', [Cf7Panel::class, 'render']);
        } else {
            add_action('admin_notices', [$this, 'missingCf7Notice']);
        }

        /* ---- Admin ---- */
        add_action('admin_menu', [new AdminMenu($this), 'register']);
        add_action('admin_enqueue_scripts', [new Assets(), 'enqueue']);
        add_action('admin_body_class', [$this, 'adminBodyClass']);
        add_action('wp_dashboard_setup', [new DashboardWidget($this), 'register']);

        /* ---- REST ---- */
        add_action('rest_api_init', [new RestController($this), 'register']);
    }

    public function loadTranslations(): void
    {
        load_plugin_textdomain(
            CF7SEC_TEXTDOMAIN,
            false,
            dirname(CF7SEC_BASENAME) . '/languages'
        );
    }

    public function cf7Active(): bool
    {
        return defined('WPCF7_VERSION') || class_exists('WPCF7_ContactForm');
    }

    /**
     * Inject the timing + honeypot control fields into every protected form.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function addHiddenFields(array $fields): array
    {
        $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;
        if ($form === null) {
            return $fields;
        }

        $formId = (int) $form->id();

        $mode = (string) $this->settings->get('general.protect_forms', 'all');
        if ($mode === 'selected'
            && !in_array($formId, array_map('intval', (array) $this->settings->get('general.form_ids', [])), true)) {
            return $fields;
        }

        if ($this->settings->isEnabled('time_check')) {
            $fields[TimeCheck::FIELD_TS]  = time();
            $fields[TimeCheck::FIELD_AGE] = ''; // filled client-side on submit
        }

        if ($this->settings->isEnabled('honeypot')) {
            $fields[HoneypotCheck::fieldName($formId)] = '';
        }

        return $fields;
    }

    /**
     * Replace CF7's generic "spam" message with the check-specific one.
     */
    public function filterSpamMessage(string $message, string $status): string
    {
        if ($status !== 'spam') {
            return $message;
        }

        return $this->pipeline->frontendMessage($message);
    }

    /**
     * Inline frontend helper: measures the human dwell time between page
     * load and form submit (monotonic — immune to clock skew and caching).
     */
    public function enqueueFrontendScript(): void
    {
        if (!wp_script_is('contact-form-7', 'enqueued') && !wp_script_is('contact-form-7', 'done')) {
            return;
        }

        $js = <<<'JS'
(function () {
    "use strict";
    var load = Date.now();
    function stamp(form) {
        if (!form || !form.querySelector) { return; }
        var field = form.querySelector('input[name="cf7sec_age"]');
        if (field) {
            field.value = String(Math.max(0, Math.round((Date.now() - load) / 1000)));
        }
    }
    document.addEventListener('submit', function (e) { stamp(e.target); }, true);
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('button[type="submit"], input[type="submit"]') : null;
        if (btn) { stamp(btn.form || (btn.closest && btn.closest('form'))); }
    }, true);
    if (window.addEventListener) {
        window.addEventListener('wpcf7submit', function (e) {
            if (e.detail && e.detail.contactFormId) {
                var form = document.querySelector('.wpcf7-form');
                stamp(form);
            }
        }, false);
    }
})();
JS;

        wp_add_inline_script('contact-form-7', $js);
    }

    /**
     * Add a marker class on our admin pages so scoped styles can override
     * the default wp-admin chrome (full-bleed layout).
     */
    public function adminBodyClass(string $classes): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && str_contains((string) $screen->id, 'cf7sec')) {
            $classes .= ' cf7sec-admin-page';
        }

        return $classes;
    }

    public function missingCf7Notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && !str_contains((string) $screen->id, 'cf7sec') && $screen->id !== 'plugins') {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            wp_kses_post(sprintf(
                /* translators: %s: plugin name */
                __('<strong>%s</strong> is active, but Contact Form 7 was not detected. Spam protection will engage automatically once Contact Form 7 is installed and activated.', 'contact-form-7-spam-checker'),
                'CF7 Spam Checker Pro'
            ))
        );
    }
}
