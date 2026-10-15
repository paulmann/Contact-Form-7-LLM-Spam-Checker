<?php
/**
 * Integrations page — LLM gateway (11 providers / 2 universal protocols) + AbuseIPDB.
 *
 * @package spam-checker-for-contact-form-7
 * @var \CF7Sec\Plugin $plugin
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use CF7Sec\Admin\Views\Ui;
use CF7Sec\Services\Llm\LlmGateway;
use CF7Sec\Services\Llm\ProviderCatalog;
use CF7Sec\Settings;

$s = $plugin->settings;

$currentProvider = (string) $s->get('llm.provider', 'deepseek');
$providerLabels  = [];
foreach (ProviderCatalog::PROVIDERS as $pid => $def) {
    $providerLabels[$pid] = $def['label'] . ' · ' . strtoupper($def['protocol']) . ' protocol';
}
?>
<div class="c-page-head">
    <div>
        <h2 class="c-page-title"><?= esc_html__('Integrations', 'spam-checker-for-contact-form-7') ?></h2>
        <p class="c-page-subtitle"><?= esc_html__('External intelligence: AI classification and IP reputation.', 'spam-checker-for-contact-form-7') ?></p>
    </div>
</div>

<form id="cf7sec-integrations-form" data-settings-form autocomplete="off">

<!-- ================= LLM GATEWAY ================= -->
<?= Ui::card(
    __('AI spam analysis (LLM gateway)', 'spam-checker-for-contact-form-7'),
    __('Sends the full submission (fields, IP, browser, geo, timing) to the large language model of your choice, which must answer with exactly one word: SPAM or CLEAN. SPAM verdicts are written to the spam registry and the submission is rejected; CLEAN submissions pass.', 'spam-checker-for-contact-form-7'),
    Ui::switch('llm.enabled', (bool) $s->get('llm.enabled', false), __('Enable LLM check', 'spam-checker-for-contact-form-7'), __('Master switch — "check via LLM".', 'spam-checker-for-contact-form-7'))
    . Ui::select('llm.provider', $providerLabels, $currentProvider, __('Provider', 'spam-checker-for-contact-form-7'),
        __('Every provider speaks one of two universal protocols: OpenAI-compatible chat completions or Anthropic Messages. The two "Custom" entries accept any operator exposing either protocol.', 'spam-checker-for-contact-form-7'))

    /* ---- per-provider credentials blocks (shown for the selected provider) ---- */
    . implode('', array_map(static function (string $pid, array $def) use ($s): string {
        $key   = (string) $s->get('llm.api_keys.' . $pid, '');
        $model = (string) $s->get('llm.models.' . $pid, '');
        $base  = (string) $s->get('llm.base_urls.' . $pid, '');
        $hint  = '';
        if ($def['key_url'] !== '') {
            $hint = __('Create one at ', 'spam-checker-for-contact-form-7')
                . '<a href="' . esc_url($def['key_url']) . '" target="_blank" rel="noopener">' . esc_html((string) preg_replace('~^https?://~', '', $def['key_url'])) . '</a>. ';
        }
        $hint .= __('The key is stored in the WordPress database and masked in this UI.', 'spam-checker-for-contact-form-7');

        $models = $def['models'];
        $listId = 'cf7sec-models-' . $pid;

        return '<div class="c-provider-block" data-show-if="llm.provider" data-show-value="' . esc_attr($pid) . '">'
            . Ui::secret('llm.api_keys.' . $pid, Settings::maskSecret($key), sprintf('%s — %s', $def['label'], __('API key', 'spam-checker-for-contact-form-7')), $hint)
            . Ui::text(
                'llm.models.' . $pid,
                $model,
                __('Model', 'spam-checker-for-contact-form-7'),
                $models !== []
                    ? __('Suggestions: ', 'spam-checker-for-contact-form-7') . esc_html(implode(', ', $models)) . '. ' . __('Any custom model id is accepted.', 'spam-checker-for-contact-form-7')
                    : __('Model id used by your gateway, e.g. mistralai/mistral-7b-instruct.', 'spam-checker-for-contact-form-7'),
                ['list' => $listId, 'placeholder' => $models[0] ?? 'model-id', 'spellcheck' => 'false']
            )
            . '<datalist id="' . esc_attr($listId) . '">' . implode('', array_map(
                static fn(string $m): string => '<option value="' . esc_attr($m) . '"></option>',
                $models
            )) . '</datalist>'
            . Ui::text(
                'llm.base_urls.' . $pid,
                $base,
                __('API base URL override', 'spam-checker-for-contact-form-7'),
                $def['base'] !== ''
                    ? __('Default: ', 'spam-checker-for-contact-form-7') . '<code>' . esc_html($def['base']) . '</code>. ' . __('Leave empty unless you use a proxy, regional endpoint or self-hosted gateway.', 'spam-checker-for-contact-form-7')
                    : __('Required for custom providers, e.g. https://api.my-operator.com/v1', 'spam-checker-for-contact-form-7'),
                ['placeholder' => $def['base'] !== '' ? $def['base'] : 'https://…', 'spellcheck' => 'false']
            )
            . ($def['note'] !== '' ? '<p class="c-field-hint">' . esc_html(__($def['note'], 'spam-checker-for-contact-form-7')) . '</p>' : '')
            . '</div>';
    }, array_keys(ProviderCatalog::PROVIDERS), array_values(ProviderCatalog::PROVIDERS)))

    /* ---- shared behaviour ---- */
    . Ui::grid(
        Ui::number('llm.timeout', (int) $s->get('llm.timeout', 20), __('Timeout (seconds)', 'spam-checker-for-contact-form-7'), __('Reasoner models can take 10–30 s.', 'spam-checker-for-contact-form-7'), ['min' => 5, 'max' => 120]),
        Ui::select('llm.on_error', [
            'allow' => __('Allow (fail-open — recommended)', 'spam-checker-for-contact-form-7'),
            'block' => __('Block (fail-closed)', 'spam-checker-for-contact-form-7'),
        ], (string) $s->get('llm.on_error', 'allow'), __('On API error / unparsable answer', 'spam-checker-for-contact-form-7'))
    )
    . Ui::switch('llm.include_geo', (bool) $s->get('llm.include_geo', true), __('Include geo/ISP data in the prompt', 'spam-checker-for-contact-form-7'))
    . Ui::switch('llm.run_when_flagged', (bool) $s->get('llm.run_when_flagged', false), __('Run LLM even when local checks already flagged spam', 'spam-checker-for-contact-form-7'), __('Off = save tokens: the LLM is consulted only for submissions that passed local heuristics.', 'spam-checker-for-contact-form-7'))
    . Ui::switch('llm.ban_on_spam', (bool) $s->get('llm.ban_on_spam', false), __('Auto-ban IP on SPAM verdict', 'spam-checker-for-contact-form-7'), __('Uses the ban duration from Settings → Protection.', 'spam-checker-for-contact-form-7'))
    . '<details class="c-details"><summary>' . esc_html__('View the system prompt sent to the model', 'spam-checker-for-contact-form-7') . '</summary><pre class="c-pre">' . esc_html(LlmGateway::systemPrompt()) . '</pre>'
    . '<p class="c-field-hint">' . esc_html__('Each submission is appended as a structured digest: form fields, IP, country/ISP (when known), user agent, referrer, dwell time and the results of the local heuristics. The digest is capped at ~12 KB.', 'spam-checker-for-contact-form-7') . '</p></details>',
    '<div class="c-card-footer-row">'
    . '<span class="c-muted c-hint-sm">' . esc_html__('Test sends one harmless sample submission to the configured provider.', 'spam-checker-for-contact-form-7') . '</span>'
    . '<button type="button" class="c-btn c-btn-primary" data-action="test-llm">' . Ui::icon('zap', 'c-icon c-icon-sm') . ' ' . esc_html__('Test connection', 'spam-checker-for-contact-form-7') . '</button>'
    . '</div>'
) ?>

<!-- ================= ABUSEIPDB ================= -->
<?= Ui::card(
    __('AbuseIPDB reputation check', 'spam-checker-for-contact-form-7'),
    __('Queries abuseipdb.com for the submitter IP: abuse confidence score, total community reports, country, ISP and Tor status. Results are cached locally to respect the daily API quota.', 'spam-checker-for-contact-form-7'),
    Ui::switch('abuseipdb.enabled', (bool) $s->get('abuseipdb.enabled', false), __('Enable API check', 'spam-checker-for-contact-form-7'), __('Master switch — "check via AbuseIPDB API".', 'spam-checker-for-contact-form-7'))
    . Ui::secret('abuseipdb.api_key', Settings::maskSecret((string) $s->get('abuseipdb.api_key', '')), __('AbuseIPDB API key', 'spam-checker-for-contact-form-7'), __('Free at ', 'spam-checker-for-contact-form-7') . '<a href="https://www.abuseipdb.com/account/api" target="_blank" rel="noopener">abuseipdb.com/account/api</a>. ' . sprintf(__('Remaining daily budget: %d queries.', 'spam-checker-for-contact-form-7'), $plugin->abuseipdb->remainingBudget()))
    . '<div class="c-callout c-callout-info">' . Ui::icon('alert', 'c-icon c-icon-sm') . '<div>'
    . '<strong>' . esc_html__('How the three thresholds work', 'spam-checker-for-contact-form-7') . '</strong>'
    . '<p>' . esc_html__('A submission is flagged when ANY threshold is met: (1) abuse confidence score, (2) total community reports across all categories, (3) reports filed under the "Email Spam" category. Note: the API returns per-category data only for the most recent reports of an IP (verbose mode), so threshold 3 counts recent Email-Spam reports — a close approximation of the full category history. Set any threshold to 0 to disable it. IPs whitelisted upstream always pass.', 'spam-checker-for-contact-form-7') . '</p>'
    . '</div></div>'
    . Ui::grid(
        Ui::number('abuseipdb.min_confidence', (int) $s->get('abuseipdb.min_confidence', 50), __('Block from abuse confidence ≥ … %', 'spam-checker-for-contact-form-7'), __('abuseConfidenceScore 0–100. Community consensus suggests 25–50 for aggressive, 75+ for conservative filtering.', 'spam-checker-for-contact-form-7'), ['min' => 0, 'max' => 100, 'step' => 1])
            . '<input type="range" class="c-range" min="0" max="100" step="5" data-range-for="abuseipdb.min_confidence" value="' . esc_attr((string) (int) $s->get('abuseipdb.min_confidence', 50)) . '" aria-label="' . esc_attr__('Abuse confidence threshold', 'spam-checker-for-contact-form-7') . '">',
        Ui::number('abuseipdb.min_total_reports', (int) $s->get('abuseipdb.min_total_reports', 0), __('Block from total reports ≥ …', 'spam-checker-for-contact-form-7'), __('All categories combined. 0 disables this threshold. Example: 5 reports already indicates a repeat offender.', 'spam-checker-for-contact-form-7'), ['min' => 0, 'max' => 100000])
    )
    . Ui::number('abuseipdb.min_email_spam_reports', (int) $s->get('abuseipdb.min_email_spam_reports', 0), __('Block from "Email Spam" category reports ≥ …', 'spam-checker-for-contact-form-7'), __('Counted from the most recent reports returned by the API (verbose mode). 0 disables this threshold. Example: 3 means an IP reported 3+ times specifically for e-mail/form spam gets blocked.', 'spam-checker-for-contact-form-7'), ['min' => 0, 'max' => 10000])
    . Ui::grid(
        Ui::number('abuseipdb.cache_days', (int) $s->get('abuseipdb.cache_days', 7), __('Cache results for (days)', 'spam-checker-for-contact-form-7'), __('0 = always query live (burns quota fast).', 'spam-checker-for-contact-form-7'), ['min' => 0, 'max' => 90]),
        Ui::number('abuseipdb.max_age_days', (int) $s->get('abuseipdb.max_age_days', 90), __('Consider reports up to (days old)', 'spam-checker-for-contact-form-7'), __('maxAgeInDays API parameter.', 'spam-checker-for-contact-form-7'), ['min' => 1, 'max' => 365])
    )
    . Ui::switch('abuseipdb.auto_ban', (bool) $s->get('abuseipdb.auto_ban', false), __('Auto-ban IPs that fail the thresholds', 'spam-checker-for-contact-form-7'))
    . Ui::switch('abuseipdb.report_spam', (bool) $s->get('abuseipdb.report_spam', false), __('Report confirmed spammers back to AbuseIPDB', 'spam-checker-for-contact-form-7'), __('When a submission is blocked, its IP is reported under the "Email Spam" category with the block reasons as comment. Requires the same API key.', 'spam-checker-for-contact-form-7'))
    . '<fieldset class="c-field c-country-filter"><legend class="c-field-label">' . esc_html__('Country filter (uses the same lookup)', 'spam-checker-for-contact-form-7') . '</legend>'
    . Ui::switch('abuseipdb.country_filter.enabled', (bool) $s->get('abuseipdb.country_filter.enabled', false), __('Enable country filtering', 'spam-checker-for-contact-form-7'))
    . Ui::grid(
        Ui::select('abuseipdb.country_filter.mode', [
            'block' => __('Block listed countries', 'spam-checker-for-contact-form-7'),
            'allow' => __('Allow ONLY listed countries', 'spam-checker-for-contact-form-7'),
        ], (string) $s->get('abuseipdb.country_filter.mode', 'block'), __('Mode', 'spam-checker-for-contact-form-7')),
        Ui::text('abuseipdb.country_filter.countries', (string) $s->get('abuseipdb.country_filter.countries', ''), __('ISO 3166-1 alpha-2 codes (comma-separated)', 'spam-checker-for-contact-form-7'), __('e.g. US, DE, CN, RU', 'spam-checker-for-contact-form-7'))
    )
    . '</fieldset>',
    '<div class="c-card-footer-row">'
    . '<span class="c-muted c-hint-sm">' . esc_html__('Test performs a lookup of 8.8.8.8 (Google DNS); a cached result is used when available.', 'spam-checker-for-contact-form-7') . '</span>'
    . '<button type="button" class="c-btn c-btn-primary" data-action="test-abuseipdb">' . Ui::icon('globe', 'c-icon c-icon-sm') . ' ' . esc_html__('Test connection', 'spam-checker-for-contact-form-7') . '</button>'
    . '</div>'
) ?>

<?= Ui::card(
    __('Pipeline order', 'spam-checker-for-contact-form-7'),
    __('How the checks combine per submission.', 'spam-checker-for-contact-form-7'),
    '<ol class="c-pipeline">'
    . '<li><strong>' . esc_html__('Whitelist', 'spam-checker-for-contact-form-7') . '</strong> — ' . esc_html__('whitelisted IPs bypass everything.', 'spam-checker-for-contact-form-7') . '</li>'
    . '<li><strong>' . esc_html__('Local heuristics', 'spam-checker-for-contact-form-7') . '</strong> — ' . esc_html__('ban list, timing, honeypot, bot UA, rate limit, SQLi, XSS, links, keywords.', 'spam-checker-for-contact-form-7') . '</li>'
    . '<li><strong>' . esc_html__('AbuseIPDB', 'spam-checker-for-contact-form-7') . '</strong> — ' . esc_html__('runs only while the submission is still clean (saves quota).', 'spam-checker-for-contact-form-7') . '</li>'
    . '<li><strong>' . esc_html__('LLM gateway', 'spam-checker-for-contact-form-7') . '</strong> — ' . esc_html__('final AI verdict SPAM/CLEAN with geo context attached.', 'spam-checker-for-contact-form-7') . '</li>'
    . '</ol>'
) ?>

</form>
