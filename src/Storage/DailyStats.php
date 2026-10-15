<?php
/**
 * Daily aggregate counters powering dashboard charts.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Storage;

defined('ABSPATH') || exit;

final class DailyStats
{
    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cf7sec_daily';
    }

    /**
     * Atomically bump one or more counters for a given day (UTC).
     *
     * @param array<string, int> $increments e.g. ['processed' => 1, 'blocked' => 1]
     */
    public static function bump(string $date, array $increments): void
    {
        global $wpdb;

        $columns = ['processed', 'blocked', 'llm_calls', 'llm_spam', 'abuse_calls', 'abuse_flagged', 'bans'];
        $sets    = ['%s'];
        $values  = [$date];

        foreach ($columns as $col) {
            $delta    = (int) ($increments[$col] ?? 0);
            $sets[]   = "%d";
            $values[] = $delta;
        }

        $sql = 'INSERT INTO ' . self::table() . ' (d, ' . implode(', ', $columns) . ') VALUES (' . implode(', ', $sets) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                static fn(string $col): string => "{$col} = {$col} + VALUES({$col})",
                $columns
            ));

        $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore WordPress.DB
    }

    /**
     * Series for charts: last N days, zero-filled.
     *
     * @return array{days: array<int, string>, processed: array<int, int>, blocked: array<int, int>}
     */
    public static function series(int $days = 30): array
    {
        global $wpdb;

        $days = min(max($days, 1), 365);
        $from = gmdate('Y-m-d', strtotime("-{$days} days"));

        /** @var array<int, object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT d, processed, blocked, llm_calls, llm_spam, abuse_calls, abuse_flagged, bans
             FROM ' . self::table() . ' WHERE d >= %s ORDER BY d ASC',
            $from
        )) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->d] = $row;
        }

        $out = ['days' => [], 'processed' => [], 'blocked' => [], 'llm' => [], 'abuse' => [], 'bans' => []];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d   = gmdate('Y-m-d', strtotime("-{$i} days"));
            $row = $map[$d] ?? null;
            $out['days'][]      = $d;
            $out['processed'][] = (int) ($row->processed ?? 0);
            $out['blocked'][]   = (int) ($row->blocked ?? 0);
            $out['llm'][]       = (int) ($row->llm_calls ?? 0);
            $out['abuse'][]     = (int) ($row->abuse_calls ?? 0);
            $out['bans'][]      = (int) ($row->bans ?? 0);
        }

        return $out;
    }

    /**
     * Lifetime totals.
     *
     * @return array<string, int>
     */
    public static function totals(): array
    {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB
            'SELECT
                COALESCE(SUM(processed),0) AS processed,
                COALESCE(SUM(blocked),0) AS blocked,
                COALESCE(SUM(llm_calls),0) AS llm_calls,
                COALESCE(SUM(llm_spam),0) AS llm_spam,
                COALESCE(SUM(abuse_calls),0) AS abuse_calls,
                COALESCE(SUM(abuse_flagged),0) AS abuse_flagged,
                COALESCE(SUM(bans),0) AS bans
             FROM ' . self::table(),
            ARRAY_A
        );

        return array_map('intval', $row ?: array_fill(0, 7, 0));
    }

    public static function purgeOlderThan(int $days): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d', time() - max(1, $days) * DAY_IN_SECONDS);

        return (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'DELETE FROM ' . self::table() . ' WHERE d < %s',
            $cutoff
        ));
    }
}
