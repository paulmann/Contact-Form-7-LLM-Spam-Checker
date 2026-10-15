<?php
/**
 * IP rules storage: bans + whitelist in one table.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Storage;

defined('ABSPATH') || exit;

/**
 * Persistent IP ban / whitelist rules.
 *
 * `expires_at = NULL` means permanent. `banned_count` drives escalation:
 * every re-ban of the same IP increments it, and once it reaches the
 * configured threshold the rule becomes permanent automatically.
 */
final class IpRuleRepository
{
    public const TYPE_BAN       = 'ban';
    public const TYPE_WHITELIST = 'whitelist';
    /** Placeholder row tracking spam strikes for an IP that is not (yet) banned. */
    public const TYPE_STRIKE    = 'strike';

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cf7sec_ip_rules';
    }

    /**
     * Insert or update a rule.
     *
     * @param array{list_type?: string, reason?: string, source?: string,
     *              expires_at?: string|null, bump_banned_count?: bool} $data
     */
    public static function put(string $ip, array $data = []): void
    {
        global $wpdb;

        $type = ($data['list_type'] ?? self::TYPE_BAN) === self::TYPE_WHITELIST
            ? self::TYPE_WHITELIST : self::TYPE_BAN;

        $existing = self::find($ip);

        if ($existing !== null) {
            $bannedCount = (int) $existing->banned_count + (!empty($data['bump_banned_count']) ? 1 : 0);
            $wpdb->update( // phpcs:ignore WordPress.DB
                self::table(),
                [
                    'list_type'    => $type,
                    'reason'       => mb_substr((string) ($data['reason'] ?? $existing->reason), 0, 250),
                    'source'       => (string) ($data['source'] ?? $existing->source),
                    'banned_count' => $bannedCount,
                    'expires_at'   => array_key_exists('expires_at', $data) ? $data['expires_at'] : $existing->expires_at,
                ],
                ['ip' => $ip]
            );

            return;
        }

        $wpdb->insert( // phpcs:ignore WordPress.DB
            self::table(),
            [
                'ip'           => $ip,
                'list_type'    => $type,
                'reason'       => mb_substr((string) ($data['reason'] ?? ''), 0, 250),
                'source'       => (string) ($data['source'] ?? 'manual'),
                'strikes'      => 0,
                'banned_count' => 1,
                'created_at'   => gmdate('Y-m-d H:i:s'),
                'expires_at'   => $data['expires_at'] ?? null,
            ]
        );
    }

    public static function find(string $ip): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT * FROM ' . self::table() . ' WHERE ip = %s',
            $ip
        ));

        return $row ?: null;
    }

    /**
     * Is the IP actively banned right now? (expired rows are ignored/pruned)
     *
     * Only rows with list_type `ban` count — `strike` placeholder rows never
     * block traffic on their own.
     */
    public static function isBanned(string $ip): bool
    {
        $rule = self::find($ip);
        if ($rule === null || $rule->list_type !== self::TYPE_BAN) {
            return false;
        }
        if ($rule->expires_at === null) {
            return true; // permanent
        }

        $expires = strtotime((string) $rule->expires_at . ' UTC');

        return $expires !== false && $expires > time();
    }

    public static function isWhitelisted(string $ip): bool
    {
        $rule = self::find($ip);

        return $rule !== null && $rule->list_type === self::TYPE_WHITELIST;
    }

    public static function delete(string $ip): bool
    {
        global $wpdb;

        return (bool) $wpdb->delete(self::table(), ['ip' => $ip]); // phpcs:ignore WordPress.DB
    }

    /**
     * @param array<int, string> $ips
     */
    public static function deleteMany(array $ips): int
    {
        global $wpdb;

        $ips = array_values(array_filter(array_map('strval', $ips), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        if ($ips === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ips), '%s'));

        return (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'DELETE FROM ' . self::table() . " WHERE ip IN ({$placeholders})",
            $ips
        ));
    }

    /**
     * Register one spam strike for an IP and return the new strike count.
     *
     * Creates/updates a `strike` placeholder row — it must NEVER be created
     * as a `ban` row, otherwise the IP would be blocked without a real ban.
     */
    public static function addStrike(string $ip): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'INSERT INTO ' . self::table() . " (ip, list_type, source, strikes, banned_count, created_at)
             VALUES (%s, 'strike', 'auto', 1, 0, %s)
             ON DUPLICATE KEY UPDATE
                strikes = IF(list_type = 'strike', strikes + 1, strikes),
                created_at = IF(list_type = 'strike', VALUES(created_at), created_at)",
            $ip,
            $now
        ));

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT strikes FROM ' . self::table() . ' WHERE ip = %s',
            $ip
        ));
    }

    public static function resetStrikes(string $ip): void
    {
        global $wpdb;

        $wpdb->update(self::table(), ['strikes' => 0], ['ip' => $ip]); // phpcs:ignore WordPress.DB
    }

    /**
     * @param array{type?: string, search?: string, page?: int, per_page?: int} $args
     *
     * @return array{items: array<int, object>, total: int}
     */
    public static function list(array $args = []): array
    {
        global $wpdb;

        $per    = min(max((int) ($args['per_page'] ?? 20), 1), 500);
        $page   = max((int) ($args['page'] ?? 1), 1);
        $offset = ($page - 1) * $per;

        $where  = '';
        $params = [];
        if (in_array($args['type'] ?? '', [self::TYPE_BAN, self::TYPE_WHITELIST], true)) {
            $where   .= ' AND list_type = %s';
            $params[] = (string) $args['type'];
        }
        if (!empty($args['search'])) {
            $where   .= ' AND (ip LIKE %s OR reason LIKE %s)';
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $table = self::table();

        /** @var array<int, object>|null $items */
        $items = $params === []
            ? $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT {$per} OFFSET {$offset}") // phpcs:ignore WordPress.DB
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE 1=1 {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", [...$params, $per, $offset])); // phpcs:ignore WordPress.DB
        $total = (int) $wpdb->get_var(
            $params === []
                ? "SELECT COUNT(*) FROM {$table}" // phpcs:ignore WordPress.DB
                : $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE 1=1 {$where}", $params) // phpcs:ignore WordPress.DB
        );

        return ['items' => $items ?: [], 'total' => $total];
    }

    public static function purgeExpired(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            "DELETE FROM " . self::table() . " WHERE list_type = 'ban' AND expires_at IS NOT NULL AND expires_at < %s",
            gmdate('Y-m-d H:i:s')
        ));
    }

    /**
     * Decay old strike rows (GDPR-friendly: strikes only count within the
     * configured retention window).
     */
    public static function purgeOldStrikes(int $days): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);

        return (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            "DELETE FROM " . self::table() . " WHERE list_type = 'strike' AND created_at < %s",
            $cutoff
        ));
    }

    public static function countActiveBans(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT COUNT(*) FROM " . self::table() . "
             WHERE list_type = 'ban' AND (expires_at IS NULL OR expires_at > %s)",
            gmdate('Y-m-d H:i:s')
        ));
    }

    public static function countWhitelist(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE list_type = %s',
            self::TYPE_WHITELIST
        ));
    }
}
