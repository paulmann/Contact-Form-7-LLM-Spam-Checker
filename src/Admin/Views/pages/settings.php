<?php
/**
 * Settings page — general, protection checks, messages, notifications, diagnostics.
 *
 * @package spam-checker-for-contact-form-7
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\Views\Ui;
use CF7Sec\Languages;
use CF7Sec\Services\Logger;

$s = $plugin->settings;

$forms = get_posts([
    'post_type'   => 'wpcf7_contact_form',
    'post_status' => 'publish',
    'numberposts' => 200,
]);

$tabs = [
    'general'       => __('General', 'spam-checker-for-contact-form-7'),
    'protection'    => __('Protection', 'spam-checker-for-contact-form-7'),
    'messages'      => __('Messages', 'spam-checker-for-contact-form-7'),
    'notifications' => __('Notifications', 'spam-checker-for-contact-form-7'),
    'diagnostics'   => __('Diagnostics', 'spam-checker-for-contact-form-7'),
];
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Settings', 'spam-checker-for-contact-form-7') ?></h2>
        <p class="c-page-subtitle"><?= esc_html__('Tune every protection layer. Changes are saved with the button in the top bar.', 'spam-checker-for-contact-form-7') ?></p>
    </div>
</div>

<div class="c-tabs" role="tablist" data-tabs>
    <?php $i = 0; foreach ($tabs as $key => $label) : ?>
        <button type="button" role="tab" class="c-tab<?= $i === 0 ? ' c-tab-active' : '' ?>" data-tab-target="<?= esc_attr($key) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= esc_html($label) ?></button>
    <?php $i++; endforeach; ?>
</div>

<form id="cf7sec-settings-form" data-settings-form autocomplete="off">

<!-- ============ GENERAL ============ -->
<section class="c-tab-panel" data-tab-panel="general">
    <?= Ui::card(
        __('Form protection scope', 'spam-checker-for-contact-form-7'),
        __('Choose which Contact Form 7 forms the pipeline protects.', 'spam-checker-for-contact-form-7'),
        Ui::select('general.protect_forms', [
            'all'      => __('All forms (recommended)', 'spam-checker-for-contact-form-7'),
            'selected' => __('Only selected forms', 'spam-checker-for-contact-form-7'),
        ], (string) $s->get('general.protect_forms', 'all'), __('Protected forms', 'spam-checker-for-contact-form-7'))
        . '<div class="c-form-picker" data-show-if="general.protect_forms" data-show-value="selected">'
        . ($forms === []
            ? '<p class="c-muted">' . esc_html__('No Contact Form 7 forms found.', 'spam-checker-for-contact-form-7') . '</p>'
            : implode('', array_map(static function ($form) use ($s): string {
                $selected = in_array((int) $form->ID, array_map('intval', (array) $s->get('general.form_ids', [])), true);
                return Ui::checkbox('general.form_ids[]', $selected, sprintf('#%d — %s', $form->ID, get_the_title($form)));
            }, $forms)))
        . '</div>'
    ) ?>

    <?= Ui::card(
        __('Logging & data retention', 'spam-checker-for-contact-form-7'),
        __('What the spam registry stores and for how long (GDPR).', 'spam-checker-for-contact-form-7'),
        Ui::grid(
            Ui::select('general.log_verdicts', [
                'blocked' => __('Blocked submissions only (recommended)', 'spam-checker-for-contact-form-7'),
                'all'     => __('Every submission (blocked + clean)', 'spam-checker-for-contact-form-7'),
                'none'    => __('Nothing — counters only', 'spam-checker-for-contact-form-7'),
            ], (string) $s->get('general.log_verdicts', 'blocked'), __('Registry verbosity', 'spam-checker-for-contact-form-7')),
            Ui::number('general.retention_days', (int) $s->get('general.retention_days', 30), __('Retention (days)', 'spam-checker-for-contact-form-7'), __('Events older than this are deleted by the daily cron.', 'spam-checker-for-contact-form-7'), ['min' => 1, 'max' => 3650])
        )
        . Ui::switch('general.show_admin_widget', (bool) $s->get('general.show_admin_widget', true), __('WordPress dashboard widget', 'spam-checker-for-contact-form-7'), __('Show the daily summary on the main WP dashboard.', 'spam-checker-for-contact-form-7'))
        . Ui::switch('uninstall.keep_data', (bool) $s->get('uninstall.keep_data', false), __('Keep data on uninstall', 'spam-checker-for-contact-form-7'), __('When off, deleting the plugin removes all tables, options and logs.', 'spam-checker-for-contact-form-7'))
    ) ?>

    <?= Ui::card(
        __('Client IP detection', 'spam-checker-for-contact-form-7'),
        __('How the real visitor IP is resolved behind proxies and CDNs.', 'spam-checker-for-contact-form-7'),
        Ui::select('general.ip_header_mode', [
            'auto'        => __('Auto — trust forwarded headers only from local/Cloudflare proxies (recommended)', 'spam-checker-for-contact-form-7'),
            'remote_only' => __('REMOTE_ADDR only — ignore all headers', 'spam-checker-for-contact-form-7'),
            'cf'          => __('Cloudflare — always use CF-Connecting-IP', 'spam-checker-for-contact-form-7'),
            'xff'         => __('Always use X-Forwarded-For', 'spam-checker-for-contact-form-7'),
        ], (string) $s->get('general.ip_header_mode', 'auto'), __('Header mode', 'spam-checker-for-contact-form-7'),
            __('Your current request resolves to: ', 'spam-checker-for-contact-form-7') . '<code>' . esc_html(\CF7Sec\Support\Ip::clientFromSettings($s)) . '</code>. '
            . __('On shared hosting behind a proxy you do not control, choose "REMOTE_ADDR only" — forwarded headers can then no longer be spoofed by the client.', 'spam-checker-for-contact-form-7'))
    ) ?>
</section>

<!-- ============ PROTECTION ============ -->
<section class="c-tab-panel" data-tab-panel="protection" hidden>
    <div class="c-checks-grid">

    <?= Ui::card(
        __('Timing analysis', 'spam-checker-for-contact-form-7'),
        __('Humans need a few seconds to fill a form; bots do not.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.time_check.enabled', $s->isEnabled('time_check'), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::grid(
            Ui::number('checks.time_check.min_seconds', (int) $s->get('checks.time_check.min_seconds', 3), __('Minimum seconds', 'spam-checker-for-contact-form-7'), '', ['min' => 0, 'max' => 300]),
            Ui::number('checks.time_check.max_age_seconds', (int) $s->get('checks.time_check.max_age_seconds', 3600), __('Maximum form age (s)', 'spam-checker-for-contact-form-7'), '', ['min' => 60, 'max' => 604800])
        )
        . Ui::number('checks.time_check.skew_tolerance', (int) $s->get('checks.time_check.skew_tolerance', 300), __('Clock-skew tolerance (s)', 'spam-checker-for-contact-form-7'), __('Only used for the server-timestamp fallback when JavaScript is disabled.', 'spam-checker-for-contact-form-7'), ['min' => 0, 'max' => 86400]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Honeypot', 'spam-checker-for-contact-form-7'),
        __('An invisible field no human should ever fill.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.honeypot.enabled', $s->isEnabled('honeypot'), __('Enabled', 'spam-checker-for-contact-form-7')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('IP ban list', 'spam-checker-for-contact-form-7'),
        __('Instantly rejects IPs found in the ban list.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.ip_rules.enabled', $s->isEnabled('ip_rules'), __('Enabled', 'spam-checker-for-contact-form-7')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Rate limiting & auto-ban', 'spam-checker-for-contact-form-7'),
        __('60-second window per IP with strike escalation. The counter is incremented atomically, so simultaneous submissions are all counted.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.rate_limit.enabled', $s->isEnabled('rate_limit'), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::grid(
            Ui::number('checks.rate_limit.max_per_minute', (int) $s->get('checks.rate_limit.max_per_minute', 20), __('Max submissions / min', 'spam-checker-for-contact-form-7'), '', ['min' => 1, 'max' => 10000]),
            Ui::number('checks.rate_limit.strike_threshold', (int) $s->get('checks.rate_limit.strike_threshold', 50), __('Strikes before ban', 'spam-checker-for-contact-form-7'), '', ['min' => 1, 'max' => 10000])
        )
        . Ui::grid(
            Ui::number('checks.rate_limit.ban_duration_hours', (int) $s->get('checks.rate_limit.ban_duration_hours', 1), __('Ban duration (hours)', 'spam-checker-for-contact-form-7'), '', ['min' => 1, 'max' => 8760]),
            Ui::number('checks.rate_limit.permanent_after_strikes', (int) $s->get('checks.rate_limit.permanent_after_strikes', 3), __('Permanent after N bans', 'spam-checker-for-contact-form-7'), __('Escalation: the Nth ban of the same IP becomes permanent.', 'spam-checker-for-contact-form-7'), ['min' => 1, 'max' => 100])
        )
        . Ui::switch('checks.rate_limit.auto_ban', (bool) $s->get('checks.rate_limit.auto_ban', true), __('Auto-ban on strike threshold', 'spam-checker-for-contact-form-7')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('SQL-injection detection', 'spam-checker-for-contact-form-7'),
        __('Signature scanning of every submitted field.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.sql_injection.enabled', $s->isEnabled('sql_injection'), __('Enabled', 'spam-checker-for-contact-form-7')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('XSS detection', 'spam-checker-for-contact-form-7'),
        __('Script tags, event handlers and encoded payloads.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.xss.enabled', $s->isEnabled('xss'), __('Enabled', 'spam-checker-for-contact-form-7')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Bot user-agent detection', 'spam-checker-for-contact-form-7'),
        __('Flags scripted clients (curl, headless browsers, scrapers…).', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.bot_ua.enabled', $s->isEnabled('bot_ua'), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::textarea('checks.bot_ua.extra_keywords', (string) $s->get('checks.bot_ua.extra_keywords', ''), __('Extra keywords (one per line)', 'spam-checker-for-contact-form-7'), '', ['rows' => 2]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Link spam', 'spam-checker-for-contact-form-7'),
        __('Blocks fields containing too many URLs.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.link_spam.enabled', $s->isEnabled('link_spam'), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::number('checks.link_spam.max_links', (int) $s->get('checks.link_spam.max_links', 2), __('Max links per field', 'spam-checker-for-contact-form-7'), '', ['min' => 0, 'max' => 100]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Keyword blacklist', 'spam-checker-for-contact-form-7'),
        __('Case-insensitive substring matching, one keyword per line; multi-word phrases are supported, and tokens shorter than 4 characters are ignored. Patterns are curated in the project pattern bank (docs/spam-patterns.md), which the project owner replenishes separately — the built-in default list is never changed by that process.', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.keyword_spam.enabled', $s->isEnabled('keyword_spam'), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::textarea('checks.keyword_spam.keywords', (string) $s->get('checks.keyword_spam.keywords', ''), __('Keywords', 'spam-checker-for-contact-form-7'), '', ['rows' => 6]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Language validation', 'spam-checker-for-contact-form-7'),
        __('Restricts name-like fields to one character set (22 languages).', 'spam-checker-for-contact-form-7'),
        Ui::switch('checks.language.enabled', $s->isEnabled('language'), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::grid(
            Ui::select('checks.language.language', Languages::names(), (string) $s->get('checks.language.language', Languages::DEFAULT_LANG), __('Allowed language', 'spam-checker-for-contact-form-7')),
            Ui::text('checks.language.fields', (string) $s->get('checks.language.fields', ''), __('Field names (comma-separated)', 'spam-checker-for-contact-form-7'))
        )
        . Ui::switch('checks.language.strict', (bool) $s->get('checks.language.strict', false), __('Strict mode', 'spam-checker-for-contact-form-7'), __('Also register violations as blocked events in the spam registry.', 'spam-checker-for-contact-form-7'))
        . Ui::textarea('checks.language.error_message', (string) $s->get('checks.language.error_message', ''), __('Custom field error message', 'spam-checker-for-contact-form-7'), __('Placeholders: %field_name%, %language_name%. Empty = use the default from the Messages tab.', 'spam-checker-for-contact-form-7'), ['rows' => 2]),
        '',
        'c-check-card'
    ) ?>

    </div>
</section>

<!-- ============ MESSAGES ============ -->
<section class="c-tab-panel" data-tab-panel="messages" hidden>
    <?= Ui::card(
        __('Visitor-facing messages', 'spam-checker-for-contact-form-7'),
        __('Shown instead of the generic CF7 spam notice when a specific check fires. Placeholders: %field_name%, %language_name%, %minutes%.', 'spam-checker-for-contact-form-7'),
        implode('', array_map(
            static fn(string $key, string $default): string => Ui::textarea(
                'messages.' . $key,
                (string) $s->get('messages.' . $key, $default),
                str_replace('_', ' ', ucfirst($key)),
                '',
                ['rows' => 2]
            ),
            array_keys(\CF7Sec\Settings::DEFAULTS['messages']),
            array_values(\CF7Sec\Settings::DEFAULTS['messages'])
        ))
    ) ?>
</section>

<!-- ============ NOTIFICATIONS ============ -->
<section class="c-tab-panel" data-tab-panel="notifications" hidden>
    <?= Ui::card(
        __('E-mail alerts', 'spam-checker-for-contact-form-7'),
        __('Notify administrators about important security events (throttled).', 'spam-checker-for-contact-form-7'),
        Ui::switch('notifications.enabled', (bool) $s->get('notifications.enabled', false), __('Enabled', 'spam-checker-for-contact-form-7'))
        . Ui::grid(
            Ui::text('notifications.email', (string) $s->get('notifications.email', ''), __('Recipient e-mail', 'spam-checker-for-contact-form-7'), __('Empty = site admin e-mail.', 'spam-checker-for-contact-form-7'), ['type' => 'email']),
            Ui::number('notifications.throttle_minutes', (int) $s->get('notifications.throttle_minutes', 60), __('Throttle (minutes)', 'spam-checker-for-contact-form-7'), __('Minimum gap between two alerts of the same type.', 'spam-checker-for-contact-form-7'), ['min' => 1, 'max' => 1440])
        )
        . '<fieldset class="c-field"><legend class="c-field-label">' . esc_html__('Notify about', 'spam-checker-for-contact-form-7') . '</legend><div class="c-checkbox-grid">'
        . implode('', array_map(
            static fn(string $key, string $label): string => Ui::checkbox(
                'notifications.events[]',
                in_array($key, (array) $s->get('notifications.events', []), true),
                $label
            ),
            ['ip_banned', 'spam_detected', 'rate_limit_ban', 'llm_error', 'abuse_error'],
            [
                __('IP banned', 'spam-checker-for-contact-form-7'),
                __('Spam blocked', 'spam-checker-for-contact-form-7'),
                __('Rate-limit ban', 'spam-checker-for-contact-form-7'),
                __('LLM provider error', 'spam-checker-for-contact-form-7'),
                __('AbuseIPDB error', 'spam-checker-for-contact-form-7'),
            ]
        ))
        . '</div></fieldset>',
        '<button type="button" class="c-btn c-btn-outline" data-action="test-email">' . Ui::icon('mail', 'c-icon c-icon-sm') . ' ' . esc_html__('Send test e-mail', 'spam-checker-for-contact-form-7') . '</button>'
    ) ?>
</section>

<!-- ============ DIAGNOSTICS ============ -->
<section class="c-tab-panel" data-tab-panel="diagnostics" hidden>
    <?= Ui::card(
        __('Debug mode', 'spam-checker-for-contact-form-7'),
        __('Writes a detailed daily log to wp-content/uploads/cf7sec/ (web-inaccessible).', 'spam-checker-for-contact-form-7'),
        Ui::switch('debug.enabled', (bool) $s->get('debug.enabled', false), __('Debug logging', 'spam-checker-for-contact-form-7'))
        . Ui::switch('debug.debug_output', (bool) $s->get('debug.debug_output', false), __('Debug output in AJAX response', 'spam-checker-for-contact-form-7'), __('Adds verdict details to the CF7 JSON response — disable on production sites.', 'spam-checker-for-contact-form-7'))
    ) ?>

    <?= Ui::card(
        __('Log viewer', 'spam-checker-for-contact-form-7'),
        '',
        '<div class="c-log-toolbar">'
        . '<select id="cf7sec-log-date" class="c-select c-select-sm"><option value="">' . esc_html__('Loading dates…', 'spam-checker-for-contact-form-7') . '</option></select>'
        . '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-action="refresh-log">' . esc_html__('Refresh', 'spam-checker-for-contact-form-7') . '</button>'
        . '<button type="button" class="c-btn c-btn-ghost c-btn-sm c-text-danger" data-action="delete-log">' . esc_html__('Delete file', 'spam-checker-for-contact-form-7') . '</button>'
        . '</div>'
        . '<pre id="cf7sec-log-view" class="c-log-view" aria-live="polite">' . esc_html__('Select a date to view the log.', 'spam-checker-for-contact-form-7') . '</pre>',
        sprintf(
            '<span class="c-muted">%s</span>',
            esc_html__('Latest 400 lines are shown. Log directory: ', 'spam-checker-for-contact-form-7') . '<code>' . esc_html(\CF7Sec\Activator::logDir()) . '</code>. '
            . esc_html__('File names carry a per-installation suffix; on nginx additionally deny the uploads/cf7sec location in the server config — .htaccess protects Apache only.', 'spam-checker-for-contact-form-7')
        )
    ) ?>
</section>

</form>
