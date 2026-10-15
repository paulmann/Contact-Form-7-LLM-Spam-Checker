<?php
/**
 * AbuseIPDB reputation check (+ optional country filtering).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;
use CF7Sec\Services\AbuseIpDb;
use CF7Sec\Storage\DailyStats;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Remote check executed late in the pipeline (only when the submission is
 * still clean, unless configured otherwise) to conserve API quota.
 *
 * Verdicts:
 *  - geo country matches the configured block/allow list → FAIL
 *  - abuseConfidenceScore >= min_confidence             → FAIL
 *  - totalReports >= min_total_reports (when > 0)       → FAIL
 *  - isWhitelisted upstream                             → PASS (trusted)
 *  - API error                                          → ERROR (fail-open,
 *    counted and visible in the registry, never blocks legit traffic)
 *
 * Note on categories: with `verbose=true` the Check API returns the lifetime
 * `totalReports` counter *plus* the most recent individual reports, each with
 * its `categories` array. The plugin counts category-10 ("Email Spam") entries
 * among those recent reports (`email_spam_reports`) and applies the
 * `abuseipdb.min_email_spam_reports` threshold to it — a close approximation
 * of the full category history, documented as such in the admin UI. The
 * `abuseipdb.min_total_reports` threshold is applied to the lifetime
 * `totalReports` value.
 */
final class AbuseIpCheck implements CheckInterface
{
    public function __construct(private readonly AbuseIpDb $client)
    {
    }

    public function id(): string
    {
        return 'abuseipdb';
    }

    public function label(): string
    {
        return __('AbuseIPDB reputation', 'contact-form-7-spam-checker');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return (bool) $context->settings->get('abuseipdb.enabled', false)
            && $this->client->isConfigured()
            && $context->ip !== ''
            && $context->ip !== '0.0.0.0';
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $record = $this->client->lookup($context->ip);

        if (is_wp_error($record)) {
            DailyStats::bump(gmdate('Y-m-d'), ['abuse_calls' => 1]);

            return CheckResult::error($this->id(), $record->get_error_message(), ['code' => $record->get_error_code()]);
        }

        if (empty($record['cached'])) {
            DailyStats::bump(gmdate('Y-m-d'), ['abuse_calls' => 1]);
        }
        $context->setGeo($record);

        $score   = (int) $record['abuse_score'];
        $reports = (int) $record['total_reports'];
        $country = (string) $record['country_code'];

        // 1. Country filter.
        $countryFail = $this->applyCountryFilter($context, $country);
        if ($countryFail !== null) {
            return $countryFail;
        }

        // 2. Upstream whitelist — trusted infrastructure, always pass.
        if (!empty($record['is_whitelisted'])) {
            return CheckResult::pass($this->id(), $this->evidence($record, 'upstream_whitelisted'));
        }

        $minConfidence = (int) $context->settings->get('abuseipdb.min_confidence', 50);
        $minReports    = (int) $context->settings->get('abuseipdb.min_total_reports', 0);

        // 3. Abuse-confidence threshold.
        if ($score >= $minConfidence) {
            DailyStats::bump(gmdate('Y-m-d'), ['abuse_flagged' => 1]);

            return CheckResult::fail(
                $this->id(),
                sprintf(
                    /* translators: 1: score, 2: threshold, 3: country */
                    __('AbuseIPDB confidence %1$d%% ≥ %2$d%% threshold (country: %3$s, reports: %4$d).', 'contact-form-7-spam-checker'),
                    $score,
                    $minConfidence,
                    $country ?: '—',
                    $reports
                ),
                'abuse_ip',
                $this->evidence($record, 'confidence')
            );
        }

        // 4. Total-reports threshold (0 disables).
        if ($minReports > 0 && $reports >= $minReports) {
            DailyStats::bump(gmdate('Y-m-d'), ['abuse_flagged' => 1]);

            return CheckResult::fail(
                $this->id(),
                sprintf(
                    /* translators: 1: report count, 2: threshold */
                    __('AbuseIPDB total reports %1$d ≥ %2$d threshold.', 'contact-form-7-spam-checker'),
                    $reports,
                    $minReports
                ),
                'abuse_ip',
                $this->evidence($record, 'reports')
            );
        }

        // 5. Email-Spam-category reports threshold (0 disables).
        $minEmailSpam = (int) $context->settings->get('abuseipdb.min_email_spam_reports', 0);
        $emailSpam    = (int) ($record['email_spam_reports'] ?? 0);
        if ($minEmailSpam > 0 && $emailSpam >= $minEmailSpam) {
            DailyStats::bump(gmdate('Y-m-d'), ['abuse_flagged' => 1]);

            return CheckResult::fail(
                $this->id(),
                sprintf(
                    /* translators: 1: email-spam report count, 2: threshold */
                    __('AbuseIPDB "Email Spam" reports %1$d ≥ %2$d threshold (counted from the most recent reports).', 'contact-form-7-spam-checker'),
                    $emailSpam,
                    $minEmailSpam
                ),
                'abuse_ip',
                $this->evidence($record, 'email_spam_reports')
            );
        }

        return CheckResult::pass($this->id(), $this->evidence($record, 'below_thresholds'));
    }

    private function applyCountryFilter(SubmissionContext $context, string $country): ?CheckResult
    {
        if (!$context->settings->get('abuseipdb.country_filter.enabled', false) || $country === '') {
            return null;
        }

        $listRaw = (string) $context->settings->get('abuseipdb.country_filter.countries', '');
        $list    = array_filter(array_map('trim', explode(',', strtoupper($listRaw))));
        if ($list === []) {
            return null;
        }

        $mode    = (string) $context->settings->get('abuseipdb.country_filter.mode', 'block');
        $inList  = in_array(strtoupper($country), $list, true);
        $blocked = ($mode === 'block' && $inList) || ($mode === 'allow' && !$inList);

        if ($blocked) {
            DailyStats::bump(gmdate('Y-m-d'), ['abuse_flagged' => 1]);

            return CheckResult::fail(
                $this->id(),
                sprintf(
                    /* translators: 1: country code, 2: mode */
                    __('Country filter: submission from %1$s is not accepted (mode: %2$s).', 'contact-form-7-spam-checker'),
                    $country,
                    $mode
                ),
                'country_blocked',
                ['country' => $country, 'mode' => $mode, 'list' => $list]
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function evidence(array $record, string $outcome): array
    {
        return [
            'outcome'       => $outcome,
            'ip'            => $record['ip'] ?? '',
            'abuse_score'   => (int) ($record['abuse_score'] ?? 0),
            'total_reports' => (int) ($record['total_reports'] ?? 0),
            'email_spam_reports' => (int) ($record['email_spam_reports'] ?? 0),
            'country'       => $record['country_code'] ?? '',
            'country_name'  => $record['country_name'] ?? '',
            'isp'           => $record['isp'] ?? '',
            'usage_type'    => $record['usage_type'] ?? '',
            'is_tor'        => (bool) ($record['is_tor'] ?? false),
            'is_whitelisted' => (bool) ($record['is_whitelisted'] ?? false),
            'last_reported' => $record['last_reported_at'],
            'from_cache'    => (bool) ($record['cached'] ?? false),
        ];
    }
}
