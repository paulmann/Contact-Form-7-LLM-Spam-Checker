<?php
/**
 * AbuseIPDB client: IP reputation check + spam reporting.
 *
 * @see https://docs.abuseipdb.com/
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Services;

use CF7Sec\Settings;
use CF7Sec\Storage\GeoCache;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * AbuseIPDB API wrapper with a DB-backed cache (respects the free-plan
 * quota of 1 000 checks/day) and an optional confirmed-spam reporter.
 */
final class AbuseIpDb
{
    private const API = 'https://api.abuseipdb.com/api/v2';

    /** AbuseIPDB category: Email Spam. */
    public const CATEGORY_EMAIL_SPAM = 10;

    /** Conservative self-imposed daily budget (free plan = 1000/day). */
    private const DAILY_BUDGET = 950;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('abuseipdb.api_key', '') !== '';
    }

    /**
     * Look up an IP: cache-first, API on miss.
     *
     * @return array<string, mixed>|WP_Error Normalised record:
     *   country_code, country_name, isp, usage_type, domain, abuse_score,
     *   total_reports, is_whitelisted, is_tor, last_reported_at, cached(bool)
     */
    public function lookup(string $ip, bool $forceRefresh = false): array|WP_Error
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return new WP_Error('cf7sec_abuse_bad_ip', __('Invalid IP address.', 'contact-form-7-spam-checker'));
        }

        $cacheDays = (int) $this->settings->get('abuseipdb.cache_days', 7);
        if (!$forceRefresh && $cacheDays > 0) {
            $cached = GeoCache::get($ip, $cacheDays);
            if ($cached !== null) {
                $cached['cached']       = true;
                $cached['abuse_score']  = (int) $cached['abuse_score'];
                $cached['total_reports'] = (int) $cached['total_reports'];
                $cached['email_spam_reports'] = (int) ($cached['email_spam_reports'] ?? 0);

                return $cached;
            }
        }

        $apiKey = (string) $this->settings->get('abuseipdb.api_key', '');
        if ($apiKey === '') {
            return new WP_Error('cf7sec_abuse_no_key', __('AbuseIPDB API key is not set.', 'contact-form-7-spam-checker'));
        }

        if (!$this->hasDailyBudget()) {
            return new WP_Error('cf7sec_abuse_budget', __('Daily AbuseIPDB query budget exhausted — cached data used when available.', 'contact-form-7-spam-checker'));
        }

        $maxAge = max(1, (int) $this->settings->get('abuseipdb.max_age_days', 90));
        $url    = add_query_arg([
            'ipAddress'  => rawurlencode($ip),
            'maxAgeInDays' => $maxAge,
            'verbose'    => 'true',
        ], self::API . '/check');

        $http = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Key'    => $apiKey,
                'Accept' => 'application/json',
            ],
        ]);

        $this->countCall($http);

        if (is_wp_error($http)) {
            return new WP_Error('cf7sec_abuse_http', $http->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($http);
        $json   = json_decode((string) wp_remote_retrieve_body($http), true);

        if ($status !== 200 || !is_array($json) || !isset($json['data'])) {
            $msg = is_array($json) && isset($json['errors'][0]['detail']) ? (string) $json['errors'][0]['detail'] : "HTTP {$status}";

            return new WP_Error('cf7sec_abuse_status', sprintf('AbuseIPDB API error: %s', $msg));
        }

        $d = $json['data'];

        // verbose=true returns the most recent individual reports, each with
        // its `categories` — count how many were filed as Email Spam (cat 10).
        $emailSpamReports = 0;
        if (isset($d['reports']) && is_array($d['reports'])) {
            foreach ($d['reports'] as $report) {
                if (isset($report['categories']) && in_array(self::CATEGORY_EMAIL_SPAM, array_map('intval', (array) $report['categories']), true)) {
                    $emailSpamReports++;
                }
            }
        }

        $record = [
            'ip'               => $ip,
            'country_code'     => (string) ($d['countryCode'] ?? ''),
            'country_name'     => (string) ($d['countryName'] ?? ''),
            'isp'              => (string) ($d['isp'] ?? ''),
            'usage_type'       => (string) ($d['usageType'] ?? ''),
            'domain'           => (string) ($d['domain'] ?? ''),
            'abuse_score'      => (int) ($d['abuseConfidenceScore'] ?? 0),
            'total_reports'    => (int) ($d['totalReports'] ?? 0),
            'email_spam_reports' => $emailSpamReports,
            'recent_reports_returned' => isset($d['reports']) && is_array($d['reports']) ? count($d['reports']) : 0,
            'is_whitelisted'   => (bool) ($d['isWhitelisted'] ?? false),
            'is_tor'           => (bool) ($d['isTor'] ?? false),
            'last_reported_at' => $d['lastReportedAt'] ?? null,
            'cached'           => false,
        ];

        GeoCache::put($ip, $record);

        return $record;
    }

    /**
     * Report a confirmed spammer IP (category: Email Spam).
     *
     * @return true|WP_Error
     */
    public function report(string $ip, string $comment = ''): true|WP_Error
    {
        $apiKey = (string) $this->settings->get('abuseipdb.api_key', '');
        if ($apiKey === '') {
            return new WP_Error('cf7sec_abuse_no_key', __('AbuseIPDB API key is not set.', 'contact-form-7-spam-checker'));
        }

        $comment = mb_substr(trim($comment), 0, 1000);
        if ($comment === '') {
            return new WP_Error('cf7sec_abuse_report_comment', __('AbuseIPDB requires a comment when reporting.', 'contact-form-7-spam-checker'));
        }

        $http = wp_remote_post(self::API . '/report', [
            'timeout' => 10,
            'headers' => [
                'Key'    => $apiKey,
                'Accept' => 'application/json',
            ],
            'body'    => [
                'ip'         => $ip,
                'categories' => (string) self::CATEGORY_EMAIL_SPAM,
                'comment'    => $comment,
            ],
        ]);

        if (is_wp_error($http)) {
            return new WP_Error('cf7sec_abuse_http', $http->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($http);
        if ($status !== 200 && $status !== 201) {
            $json = json_decode((string) wp_remote_retrieve_body($http), true);
            $msg  = is_array($json) && isset($json['errors'][0]['detail']) ? (string) $json['errors'][0]['detail'] : "HTTP {$status}";

            return new WP_Error('cf7sec_abuse_report', sprintf('AbuseIPDB report failed: %s', $msg));
        }

        return true;
    }

    /**
     * Connectivity self-test for the admin UI.
     *
     * @return array{ok: bool, message: string}|WP_Error
     */
    public function test(): array|WP_Error
    {
        // Google DNS — whitelisted upstream, perfect for a credentials check.
        $result = $this->lookup('8.8.8.8', true);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                /* translators: 1: country code, 2: abuse score */
                __('API key is valid. Sample lookup 8.8.8.8 → country %1$s, abuse score %2$d%%.', 'contact-form-7-spam-checker'),
                $result['country_code'] ?: '—',
                (int) $result['abuse_score']
            ),
        ];
    }

    /**
     * Remaining daily API budget.
     *
     * Primarily sourced from the `x-ratelimit-remaining` response header of
     * the last API call (authoritative); falls back to a conservative
     * self-tracked counter when no call has been made yet this day.
     */
    public function remainingBudget(): int
    {
        $fromApi = get_transient('cf7sec_abuse_budget_api');
        if ($fromApi !== false) {
            return max(0, (int) $fromApi);
        }

        return max(0, self::DAILY_BUDGET - (int) get_transient('cf7sec_abuse_calls_today'));
    }

    private function hasDailyBudget(): bool
    {
        return $this->remainingBudget() > 0;
    }

    /**
     * @param array<string, mixed>|WP_Error $http
     */
    private function countCall(array|WP_Error $http): void
    {
        // Prefer the authoritative rate-limit headers when present.
        if (!is_wp_error($http) && function_exists('wp_remote_retrieve_header')) {
            $remaining = wp_remote_retrieve_header($http, 'x-ratelimit-remaining');
            $reset     = (int) (wp_remote_retrieve_header($http, 'x-ratelimit-reset') ?: 0);
            if (is_numeric($remaining)) {
                $ttl = $reset > time() ? min($reset - time(), DAY_IN_SECONDS) : HOUR_IN_SECONDS;
                set_transient('cf7sec_abuse_budget_api', (int) $remaining, max(60, $ttl));
            }
        }

        $count = (int) get_transient('cf7sec_abuse_calls_today');
        $ttl   = (int) (get_transient('cf7sec_abuse_calls_ttl') ?: 0);

        if ($count === 0 || $ttl <= time()) {
            $end = strtotime('tomorrow UTC') ?: time() + DAY_IN_SECONDS;
            set_transient('cf7sec_abuse_calls_today', 1, max(60, $end - time()));
            set_transient('cf7sec_abuse_calls_ttl', $end, max(60, $end - time()));
        } else {
            set_transient('cf7sec_abuse_calls_today', $count + 1, max(60, $ttl - time()));
        }
    }
}
