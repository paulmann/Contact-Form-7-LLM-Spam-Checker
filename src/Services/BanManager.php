<?php
/**
 * Ban / whitelist orchestration with strike escalation.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Services;

use CF7Sec\Settings;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\IpRuleRepository;

defined('ABSPATH') || exit;

/**
 * Central place that decides *when* an IP gets banned, for how long, and
 * when a temporary ban escalates to a permanent one.
 */
final class BanManager
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * Ban an IP.
     *
     * @param string   $ip
     * @param string   $reason       Human-readable reason stored with the rule.
     * @param string   $source       manual|auto|rate_limit|abuseipdb|llm|honeypot|...
     * @param int|null $durationSecs NULL → use the configured default; 0 → permanent.
     *
     * @return array{permanent: bool, expires_at: ?string, banned_count: int}
     */
    public function ban(string $ip, string $reason, string $source = 'auto', ?int $durationSecs = null): array
    {
        $existing = IpRuleRepository::find($ip);
        if ($existing !== null && $existing->list_type === IpRuleRepository::TYPE_WHITELIST) {
            // Never ban a whitelisted IP.
            return ['permanent' => false, 'expires_at' => null, 'banned_count' => (int) $existing->banned_count];
        }

        // The storage increments and returns the counter in one statement, so
        // two close-together bans can no longer both decide on the same stale
        // value and escalate one ban too late (defect S3, v4.1.1).
        $bannedCount   = IpRuleRepository::bumpBannedCount($ip);
        $escalateAfter = max(1, (int) $this->settings->get('checks.rate_limit.permanent_after_strikes', 3));

        $permanent = ($durationSecs === 0) || ($bannedCount >= $escalateAfter);
        $expiresAt = null;

        if (!$permanent) {
            $durationSecs ??= max(1, (int) $this->settings->get('checks.rate_limit.ban_duration_hours', 1)) * HOUR_IN_SECONDS;
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $durationSecs);
        }

        IpRuleRepository::put($ip, [
            'list_type'  => IpRuleRepository::TYPE_BAN,
            'reason'     => $reason,
            'source'     => $source,
            'expires_at' => $expiresAt,
        ]);
        IpRuleRepository::resetStrikes($ip);

        DailyStats::bump(gmdate('Y-m-d'), ['bans' => 1]);

        EventsRepository::record([
            'event_type' => 'ip_banned',
            'verdict'    => EventsRepository::VERDICT_INFO,
            'ip'         => $ip,
            'reasons'    => [$reason],
            'checks'     => [
                'source'       => $source,
                'permanent'    => $permanent,
                'expires_at'   => $expiresAt,
                'banned_count' => $bannedCount,
            ],
        ]);

        if ($this->settings->get('notifications.enabled', false)
            && in_array('ip_banned', (array) $this->settings->get('notifications.events', []), true)) {
            $this->mailer->notify(
                'ip_banned',
                /* translators: %s: IP address */
                sprintf(__('IP banned: %s', 'spam-checker-for-contact-form-7'), $ip),
                sprintf(
                    "%s\n%s: %s\n%s: %s\n%s: %s",
                    $reason,
                    __('Source', 'spam-checker-for-contact-form-7'), $source,
                    __('Type', 'spam-checker-for-contact-form-7'), $permanent
                        ? __('Permanent', 'spam-checker-for-contact-form-7')
                        : __('Temporary', 'spam-checker-for-contact-form-7'),
                    __('Expires (UTC)', 'spam-checker-for-contact-form-7'), $expiresAt ?? '—'
                )
            );
        }

        /**
         * Fires after an IP has been banned.
         *
         * @since 4.0.0
         *
         * @param string $ip
         * @param string $reason
         * @param bool   $permanent
         */
        do_action('cf7sec_ip_banned', $ip, $reason, $permanent);

        return ['permanent' => $permanent, 'expires_at' => $expiresAt, 'banned_count' => $bannedCount];
    }

    public function unban(string $ip): bool
    {
        return IpRuleRepository::delete($ip);
    }

    /**
     * @param array<int, string> $ips
     */
    public function unbanMany(array $ips): int
    {
        return IpRuleRepository::deleteMany($ips);
    }

    public function whitelist(string $ip, string $reason = ''): void
    {
        IpRuleRepository::put($ip, [
            'list_type' => IpRuleRepository::TYPE_WHITELIST,
            'reason'    => $reason !== '' ? $reason : __('Manually whitelisted by administrator', 'spam-checker-for-contact-form-7'),
            'source'    => 'manual',
            'expires_at' => null,
        ]);
    }

    /**
     * Register a spam strike for an IP; auto-bans once the configured
     * strike threshold is reached. Returns the new strike count.
     */
    public function strike(string $ip, string $reason): int
    {
        if (!($this->settings->get('checks.rate_limit.auto_ban', true))) {
            return 0;
        }

        $strikes   = IpRuleRepository::addStrike($ip);
        $threshold = max(1, (int) $this->settings->get('checks.rate_limit.strike_threshold', 50));

        if ($strikes >= $threshold) {
            $this->ban($ip, sprintf(
                /* translators: 1: strike count, 2: threshold */
                __('Strike threshold reached (%1$d/%2$d). Last reason: %3$s', 'spam-checker-for-contact-form-7'),
                $strikes,
                $threshold,
                $reason
            ), 'rate_limit');
        }

        return $strikes;
    }
}
