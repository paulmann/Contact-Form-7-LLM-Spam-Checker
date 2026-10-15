<?php
/**
 * Settings page — general, protection checks, messages, notifications, diagnostics.
 *
 * @package contact-form-7-spam-checker
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
    'general'       => __('General', 'contact-form-7-spam-checker'),
    'protection'    => __('Protection', 'contact-form-7-spam-checker'),
    'messages'      => __('Messages', 'contact-form-7-spam-checker'),
    'notifications' => __('Notifications', 'contact-form-7-spam-checker'),
    'diagnostics'   => __('Diagnostics', 'contact-form-7-spam-checker'),
];
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Settings', 'contact-form-7-spam-checker') ?></h2>
        <p class="c-page-subtitle"><?= esc_html__('Tune every protection layer. Changes are saved with the button in the top bar.', 'contact-form-7-spam-checker') ?></p>
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
        __('Form protection scope', 'contact-form-7-spam-checker'),
        __('Choose which Contact Form 7 forms the pipeline protects.', 'contact-form-7-spam-checker'),
        Ui::select('general.protect_forms', [
            'all'      => __('All forms (recommended)', 'contact-form-7-spam-checker'),
            'selected' => __('Only selected forms', 'contact-form-7-spam-checker'),
        ], (string) $s->get('general.protect_forms', 'all'), __('Protected forms', 'contact-form-7-spam-checker'))
        . '<div class="c-form-picker" data-show-if="general.protect_forms" data-show-value="selected">'
        . ($forms === []
            ? '<p class="c-muted">' . esc_html__('No Contact Form 7 forms found.', 'contact-form-7-spam-checker') . '</p>'
            : implode('', array_map(static function ($form) use ($s): string {
                $selected = in_array((int) $form->ID, array_map('intval', (array) $s->get('general.form_ids', [])), true);
                return Ui::checkbox('general.form_ids[]', $selected, sprintf('#%d — %s', $form->ID, get_the_title($form)));
            }, $forms)))
        . '</div>'
    ) ?>

    <?= Ui::card(
        __('Logging & data retention', 'contact-form-7-spam-checker'),
        __('What the spam registry stores and for how long (GDPR).', 'contact-form-7-spam-checker'),
        Ui::grid(
            Ui::select('general.log_verdicts', [
                'blocked' => __('Blocked submissions only (recommended)', 'contact-form-7-spam-checker'),
                'all'     => __('Every submission (blocked + clean)', 'contact-form-7-spam-checker'),
                'none'    => __('Nothing — counters only', 'contact-form-7-spam-checker'),
            ], (string) $s->get('general.log_verdicts', 'blocked'), __('Registry verbosity', 'contact-form-7-spam-checker')),
            Ui::number('general.retention_days', (int) $s->get('general.retention_days', 30), __('Retention (days)', 'contact-form-7-spam-checker'), __('Events older than this are deleted by the daily cron.', 'contact-form-7-spam-checker'), ['min' => 1, 'max' => 3650])
        )
        . Ui::switch('general.show_admin_widget', (bool) $s->get('general.show_admin_widget', true), __('WordPress dashboard widget', 'contact-form-7-spam-checker'), __('Show the daily summary on the main WP dashboard.', 'contact-form-7-spam-checker'))
        . Ui::switch('uninstall.keep_data', (bool) $s->get('uninstall.keep_data', false), __('Keep data on uninstall', 'contact-form-7-spam-checker'), __('When off, deleting the plugin removes all tables, options and logs.', 'contact-form-7-spam-checker'))
    ) ?>

    <?= Ui::card(
        __('Client IP detection', 'contact-form-7-spam-checker'),
        __('How the real visitor IP is resolved behind proxies and CDNs.', 'contact-form-7-spam-checker'),
        Ui::select('general.ip_header_mode', [
            'auto'        => __('Auto — trust forwarded headers only from local/Cloudflare proxies (recommended)', 'contact-form-7-spam-checker'),
            'remote_only' => __('REMOTE_ADDR only — ignore all headers', 'contact-form-7-spam-checker'),
            'cf'          => __('Cloudflare — always use CF-Connecting-IP', 'contact-form-7-spam-checker'),
            'xff'         => __('Always use X-Forwarded-For', 'contact-form-7-spam-checker'),
        ], (string) $s->get('general.ip_header_mode', 'auto'), __('Header mode', 'contact-form-7-spam-checker'),
            __('Your current request resolves to: ', 'contact-form-7-spam-checker') . '<code>' . esc_html(\CF7Sec\Support\Ip::clientFromSettings($s)) . '</code>. '
            . __('On shared hosting behind a proxy you do not control, choose "REMOTE_ADDR only" — forwarded headers can then no longer be spoofed by the client.', 'contact-form-7-spam-checker'))
    ) ?>
</section>

<!-- ============ PROTECTION ============ -->
<section class="c-tab-panel" data-tab-panel="protection" hidden>
    <div class="c-checks-grid">

    <?= Ui::card(
        __('Timing analysis', 'contact-form-7-spam-checker'),
        __('Humans need a few seconds to fill a form; bots do not.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.time_check.enabled', $s->isEnabled('time_check'), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::grid(
            Ui::number('checks.time_check.min_seconds', (int) $s->get('checks.time_check.min_seconds', 3), __('Minimum seconds', 'contact-form-7-spam-checker'), '', ['min' => 0, 'max' => 300]),
            Ui::number('checks.time_check.max_age_seconds', (int) $s->get('checks.time_check.max_age_seconds', 3600), __('Maximum form age (s)', 'contact-form-7-spam-checker'), '', ['min' => 60, 'max' => 604800])
        )
        . Ui::number('checks.time_check.skew_tolerance', (int) $s->get('checks.time_check.skew_tolerance', 300), __('Clock-skew tolerance (s)', 'contact-form-7-spam-checker'), __('Only used for the server-timestamp fallback when JavaScript is disabled.', 'contact-form-7-spam-checker'), ['min' => 0, 'max' => 86400]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Honeypot', 'contact-form-7-spam-checker'),
        __('An invisible field no human should ever fill.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.honeypot.enabled', $s->isEnabled('honeypot'), __('Enabled', 'contact-form-7-spam-checker')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('IP ban list', 'contact-form-7-spam-checker'),
        __('Instantly rejects IPs found in the ban list.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.ip_rules.enabled', $s->isEnabled('ip_rules'), __('Enabled', 'contact-form-7-spam-checker')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Rate limiting & auto-ban', 'contact-form-7-spam-checker'),
        __('Sliding 60-second window per IP with strike escalation.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.rate_limit.enabled', $s->isEnabled('rate_limit'), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::grid(
            Ui::number('checks.rate_limit.max_per_minute', (int) $s->get('checks.rate_limit.max_per_minute', 20), __('Max submissions / min', 'contact-form-7-spam-checker'), '', ['min' => 1, 'max' => 10000]),
            Ui::number('checks.rate_limit.strike_threshold', (int) $s->get('checks.rate_limit.strike_threshold', 50), __('Strikes before ban', 'contact-form-7-spam-checker'), '', ['min' => 1, 'max' => 10000])
        )
        . Ui::grid(
            Ui::number('checks.rate_limit.ban_duration_hours', (int) $s->get('checks.rate_limit.ban_duration_hours', 1), __('Ban duration (hours)', 'contact-form-7-spam-checker'), '', ['min' => 1, 'max' => 8760]),
            Ui::number('checks.rate_limit.permanent_after_strikes', (int) $s->get('checks.rate_limit.permanent_after_strikes', 3), __('Permanent after N bans', 'contact-form-7-spam-checker'), __('Escalation: the Nth ban of the same IP becomes permanent.', 'contact-form-7-spam-checker'), ['min' => 1, 'max' => 100])
        )
        . Ui::switch('checks.rate_limit.auto_ban', (bool) $s->get('checks.rate_limit.auto_ban', true), __('Auto-ban on strike threshold', 'contact-form-7-spam-checker')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('SQL-injection detection', 'contact-form-7-spam-checker'),
        __('Signature scanning of every submitted field.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.sql_injection.enabled', $s->isEnabled('sql_injection'), __('Enabled', 'contact-form-7-spam-checker')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('XSS detection', 'contact-form-7-spam-checker'),
        __('Script tags, event handlers and encoded payloads.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.xss.enabled', $s->isEnabled('xss'), __('Enabled', 'contact-form-7-spam-checker')),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Bot user-agent detection', 'contact-form-7-spam-checker'),
        __('Flags scripted clients (curl, headless browsers, scrapers…).', 'contact-form-7-spam-checker'),
        Ui::switch('checks.bot_ua.enabled', $s->isEnabled('bot_ua'), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::textarea('checks.bot_ua.extra_keywords', (string) $s->get('checks.bot_ua.extra_keywords', ''), __('Extra keywords (one per line)', 'contact-form-7-spam-checker'), '', ['rows' => 2]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Link spam', 'contact-form-7-spam-checker'),
        __('Blocks fields containing too many URLs.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.link_spam.enabled', $s->isEnabled('link_spam'), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::number('checks.link_spam.max_links', (int) $s->get('checks.link_spam.max_links', 2), __('Max links per field', 'contact-form-7-spam-checker'), '', ['min' => 0, 'max' => 100]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Keyword blacklist', 'contact-form-7-spam-checker'),
        __('Case-insensitive substring matching, one keyword per line.', 'contact-form-7-spam-checker'),
        Ui::switch('checks.keyword_spam.enabled', $s->isEnabled('keyword_spam'), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::textarea('checks.keyword_spam.keywords', (string) $s->get('checks.keyword_spam.keywords', ''), __('Keywords', 'contact-form-7-spam-checker'), '', ['rows' => 6]),
        '',
        'c-check-card'
    ) ?>

    <?= Ui::card(
        __('Language validation', 'contact-form-7-spam-checker'),
        __('Restricts name-like fields to one character set (22 languages).', 'contact-form-7-spam-checker'),
        Ui::switch('checks.language.enabled', $s->isEnabled('language'), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::grid(
            Ui::select('checks.language.language', Languages::names(), (string) $s->get('checks.language.language', 'russian'), __('Allowed language', 'contact-form-7-spam-checker')),
            Ui::text('checks.language.fields', (string) $s->get('checks.language.fields', ''), __('Field names (comma-separated)', 'contact-form-7-spam-checker'))
        )
        . Ui::switch('checks.language.strict', (bool) $s->get('checks.language.strict', false), __('Strict mode', 'contact-form-7-spam-checker'), __('Also register violations as blocked events in the spam registry.', 'contact-form-7-spam-checker'))
        . Ui::textarea('checks.language.error_message', (string) $s->get('checks.language.error_message', ''), __('Custom field error message', 'contact-form-7-spam-checker'), __('Placeholders: %field_name%, %language_name%. Empty = use the default from the Messages tab.', 'contact-form-7-spam-checker'), ['rows' => 2]),
        '',
        'c-check-card'
    ) ?>

    </div>
</section>

<!-- ============ MESSAGES ============ -->
<section class="c-tab-panel" data-tab-panel="messages" hidden>
    <?= Ui::card(
        __('Visitor-facing messages', 'contact-form-7-spam-checker'),
        __('Shown instead of the generic CF7 spam notice when a specific check fires. Placeholders: %field_name%, %language_name%, %minutes%.', 'contact-form-7-spam-checker'),
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
        __('E-mail alerts', 'contact-form-7-spam-checker'),
        __('Notify administrators about important security events (throttled).', 'contact-form-7-spam-checker'),
        Ui::switch('notifications.enabled', (bool) $s->get('notifications.enabled', false), __('Enabled', 'contact-form-7-spam-checker'))
        . Ui::grid(
            Ui::text('notifications.email', (string) $s->get('notifications.email', ''), __('Recipient e-mail', 'contact-form-7-spam-checker'), __('Empty = site admin e-mail.', 'contact-form-7-spam-checker'), ['type' => 'email']),
            Ui::number('notifications.throttle_minutes', (int) $s->get('notifications.throttle_minutes', 60), __('Throttle (minutes)', 'contact-form-7-spam-checker'), __('Minimum gap between two alerts of the same type.', 'contact-form-7-spam-checker'), ['min' => 1, 'max' => 1440])
        )
        . '<fieldset class="c-field"><legend class="c-field-label">' . esc_html__('Notify about', 'contact-form-7-spam-checker') . '</legend><div class="c-checkbox-grid">'
        . implode('', array_map(
            static fn(string $key, string $label): string => Ui::checkbox(
                'notifications.events[]',
                in_array($key, (array) $s->get('notifications.events', []), true),
                $label
            ),
            ['ip_banned', 'spam_detected', 'rate_limit_ban', 'llm_error', 'abuse_error'],
            [
                __('IP banned', 'contact-form-7-spam-checker'),
                __('Spam blocked', 'contact-form-7-spam-checker'),
                __('Rate-limit ban', 'contact-form-7-spam-checker'),
                __('LLM provider error', 'contact-form-7-spam-checker'),
                __('AbuseIPDB error', 'contact-form-7-spam-checker'),
            ]
        ))
        . '</div></fieldset>',
        '<button type="button" class="c-btn c-btn-outline" data-action="test-email">' . Ui::icon('mail', 'c-icon c-icon-sm') . ' ' . esc_html__('Send test e-mail', 'contact-form-7-spam-checker') . '</button>'
    ) ?>
</section>

<!-- ============ DIAGNOSTICS ============ -->
<section class="c-tab-panel" data-tab-panel="diagnostics" hidden>
    <?= Ui::card(
        __('Debug mode', 'contact-form-7-spam-checker'),
        __('Writes a detailed daily log to wp-content/uploads/cf7sec/ (web-inaccessible).', 'contact-form-7-spam-checker'),
        Ui::switch('debug.enabled', (bool) $s->get('debug.enabled', false), __('Debug logging', 'contact-form-7-spam-checker'))
        . Ui::switch('debug.debug_output', (bool) $s->get('debug.debug_output', false), __('Debug output in AJAX response', 'contact-form-7-spam-checker'), __('Adds verdict details to the CF7 JSON response — disable on production sites.', 'contact-form-7-spam-checker'))
    ) ?>

    <?= Ui::card(
        __('Log viewer', 'contact-form-7-spam-checker'),
        '',
        '<div class="c-log-toolbar">'
        . '<select id="cf7sec-log-date" class="c-select c-select-sm"><option value="">' . esc_html__('Loading dates…', 'contact-form-7-spam-checker') . '</option></select>'
        . '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-action="refresh-log">' . esc_html__('Refresh', 'contact-form-7-spam-checker') . '</button>'
        . '<button type="button" class="c-btn c-btn-ghost c-btn-sm c-text-danger" data-action="delete-log">' . esc_html__('Delete file', 'contact-form-7-spam-checker') . '</button>'
        . '</div>'
        . '<pre id="cf7sec-log-view" class="c-log-view" aria-live="polite">' . esc_html__('Select a date to view the log.', 'contact-form-7-spam-checker') . '</pre>',
        sprintf(
            '<span class="c-muted">%s</span>',
            esc_html__('Latest 400 lines are shown. Log directory: ', 'contact-form-7-spam-checker') . '<code>' . esc_html(\CF7Sec\Activator::logDir()) . '</code>. '
            . esc_html__('File names carry a per-installation suffix; on nginx additionally deny the uploads/cf7sec location in the server config — .htaccess protects Apache only.', 'contact-form-7-spam-checker')
        )
    ) ?>
</section>

</form>
