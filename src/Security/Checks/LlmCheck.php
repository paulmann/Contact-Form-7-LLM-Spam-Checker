<?php
/**
 * LLM classification check (provider-agnostic gateway).
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\LlmPrompt;
use CF7Sec\Security\SubmissionContext;
use CF7Sec\Services\Llm\LlmGateway;
use CF7Sec\Storage\DailyStats;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Sends the full submission digest (fields, IP, geo, UA, timing, local
 * verdicts) to the configured LLM provider and requires a one-word verdict: SPAM or CLEAN.
 *
 *  - SPAM  → the submission is blocked and written to the spam registry.
 *  - CLEAN → the submission passes.
 *  - API/parse error → behaviour follows the `on_error` setting
 *    (default: allow — fail-open, errors are still logged).
 */
final class LlmCheck implements CheckInterface
{
    public function __construct(private readonly LlmGateway $client)
    {
    }

    public function id(): string
    {
        return 'llm';
    }

    public function label(): string
    {
        return __('LLM analysis (your provider)', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return (bool) $context->settings->get('llm.enabled', false) && $this->client->isConfigured();
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $providerLabel = $this->client->provider()['label'];

        // Include geo in the digest when requested and not fetched yet.
        if ($context->settings->get('llm.include_geo', true) && $context->geo() === null) {
            $geo = $this->fetchGeoQuietly($context);
            if ($geo !== null) {
                $context->setGeo($geo);
            }
        }

        $digest = LlmPrompt::digest($context);
        $result = $this->client->classify($digest);

        if (is_wp_error($result)) {
            DailyStats::bump(gmdate('Y-m-d'), ['llm_calls' => 1]);

            $onError = (string) $context->settings->get('llm.on_error', 'allow');

            return $onError === 'block'
                ? CheckResult::fail(
                    $this->id(),
                    sprintf(__('LLM API error (%1$s); fail-closed policy applied.', 'spam-checker-for-contact-form-7'), $result->get_error_message()),
                    'llm_spam',
                    ['error' => $result->get_error_message(), 'policy' => 'block']
                )
                : CheckResult::error(
                    $this->id(),
                    sprintf(__('LLM API error (%1$s); fail-open policy applied.', 'spam-checker-for-contact-form-7'), $result->get_error_message()),
                    ['error' => $result->get_error_message(), 'policy' => 'allow']
                );
        }

        DailyStats::bump(gmdate('Y-m-d'), ['llm_calls' => 1]);

        if ($result['verdict'] === LlmGateway::VERDICT_SPAM) {
            DailyStats::bump(gmdate('Y-m-d'), ['llm_spam' => 1]);

            return CheckResult::fail(
                $this->id(),
                sprintf(
                    /* translators: 1: provider label, 2: model, 3: latency ms */
                    __('LLM %1$s (%2$s) classified the submission as SPAM in %3$d ms.', 'spam-checker-for-contact-form-7'),
                    $providerLabel,
                    $result['model'],
                    $result['ms']
                ),
                'llm_spam',
                [
                    'provider' => $result['provider'],
                    'protocol' => $result['protocol'],
                    'model'   => $result['model'],
                    'verdict' => $result['verdict'],
                    'ms'      => $result['ms'],
                    'usage'   => $result['usage'],
                    'digest'  => mb_substr($digest, 0, 1200),
                ]
            );
        }

        if ($result['verdict'] === LlmGateway::VERDICT_CLEAN) {
            return CheckResult::pass($this->id(), [
                'provider' => $result['provider'],
                'protocol' => $result['protocol'],
                'model'   => $result['model'],
                'verdict' => $result['verdict'],
                'ms'      => $result['ms'],
                'usage'   => $result['usage'],
            ]);
        }

        // Unparsable verdict → treat like an API error.
        $onError = (string) $context->settings->get('llm.on_error', 'allow');

        return $onError === 'block'
            ? CheckResult::fail(
                $this->id(),
                __('LLM returned an unparsable verdict; fail-closed policy applied.', 'spam-checker-for-contact-form-7'),
                'llm_spam',
                ['raw' => mb_substr($result['raw'], 0, 300), 'policy' => 'block']
            )
            : CheckResult::error(
                $this->id(),
                __('LLM returned an unparsable verdict; fail-open policy applied.', 'spam-checker-for-contact-form-7'),
                ['raw' => mb_substr($result['raw'], 0, 300), 'policy' => 'allow']
            );
    }

    /**
     * Best-effort geo enrichment from the cache (never calls the API here —
     * the AbuseIpCheck owns quota).
     *
     * @return array<string, mixed>|null
     */
    private function fetchGeoQuietly(SubmissionContext $context): ?array
    {
        $cached = \CF7Sec\Storage\GeoCache::get($context->ip, 0);

        return $cached ?? null;
    }
}
