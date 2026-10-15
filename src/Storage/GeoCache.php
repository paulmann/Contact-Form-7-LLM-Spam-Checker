<?php
/**
 * Geo / IP-reputation cache (backs AbuseIPDB lookups and country filtering).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Storage;

defined('ABSPATH') || exit;

final class GeoCache
{
    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cf7sec_geo';
    }

    /**
     * Fetch a cached lookup if it is younger than $ttlDays (0 = any age).
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $ip, int $ttlDays = 7): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT * FROM ' . self::table() . ' WHERE ip = %s',
            $ip
        ), ARRAY_A);

        if ($row === null) {
            return null;
        }

        if ($ttlDays > 0) {
            $age = time() - (strtotime((string) $row['checked_at'] . ' UTC') ?: 0);
            if ($age > $ttlDays * DAY_IN_SECONDS) {
                return null;
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data Normalised AbuseIPDB response.
     */
    public static function put(string $ip, array $data): void
    {
        global $wpdb;

        $wpdb->replace( // phpcs:ignore WordPress.DB
            self::table(),
            [
                'ip'               => $ip,
                'country_code'     => mb_substr((string) ($data['country_code'] ?? ''), 0, 2),
                'country_name'     => mb_substr((string) ($data['country_name'] ?? ''), 0, 100),
                'isp'              => mb_substr((string) ($data['isp'] ?? ''), 0, 255),
                'usage_type'       => mb_substr((string) ($data['usage_type'] ?? ''), 0, 64),
                'domain'           => mb_substr((string) ($data['domain'] ?? ''), 0, 255),
                'abuse_score'      => min(100, max(0, (int) ($data['abuse_score'] ?? 0))),
                'total_reports'    => max(0, (int) ($data['total_reports'] ?? 0)),
                'email_spam_reports' => max(0, (int) ($data['email_spam_reports'] ?? 0)),
                'is_whitelisted'   => !empty($data['is_whitelisted']) ? 1 : 0,
                'is_tor'           => !empty($data['is_tor']) ? 1 : 0,
                'last_reported_at' => !empty($data['last_reported_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $data['last_reported_at']) ?: time()) : null,
                'checked_at'       => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public static function forget(string $ip): void
    {
        global $wpdb;

        $wpdb->delete(self::table(), ['ip' => $ip]); // phpcs:ignore WordPress.DB
    }

    public static function purgeOlderThan(int $days): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);

        return (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'DELETE FROM ' . self::table() . ' WHERE checked_at < %s',
            $cutoff
        ));
    }

    public static function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table()); // phpcs:ignore WordPress.DB
    }

    public static function truncate(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::table()); // phpcs:ignore WordPress.DB
    }
}
