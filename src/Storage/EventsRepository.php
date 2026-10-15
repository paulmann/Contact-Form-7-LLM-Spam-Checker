<?php
/**
 * Spam & security event registry (custom DB table).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Storage;

defined('ABSPATH') || exit;

/**
 * Every verdict produced by the pipeline (blocked spam, allowed submissions,
 * LLM/API results, bans) is stored here — this is the "spam registry".
 */
final class EventsRepository
{
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_ALLOWED = 'allowed';
    public const VERDICT_ERROR   = 'error';
    public const VERDICT_INFO    = 'info';

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'cf7sec_events';
    }

    /**
     * @param array<string, mixed> $event Keys: form_id, ip, country, event_type,
     *                                    verdict, reasons[], checks[], payload[],
     *                                    user_agent, referrer.
     */
    public static function record(array $event): int
    {
        global $wpdb;

        $row = [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'form_id'    => (int) ($event['form_id'] ?? 0),
            'ip'         => (string) ($event['ip'] ?? ''),
            'country'    => (string) ($event['country'] ?? ''),
            'event_type' => (string) ($event['event_type'] ?? 'spam_blocked'),
            'verdict'    => (string) ($event['verdict'] ?? self::VERDICT_BLOCKED),
            'reasons'    => isset($event['reasons']) ? wp_json_encode(array_values((array) $event['reasons'])) : null,
            'checks'     => isset($event['checks']) ? wp_json_encode($event['checks']) : null,
            'payload'    => isset($event['payload']) ? wp_json_encode($event['payload']) : null,
            'user_agent' => mb_substr((string) ($event['user_agent'] ?? ''), 0, 512),
            'referrer'   => mb_substr((string) ($event['referrer'] ?? ''), 0, 1024),
        ];

        $wpdb->insert(self::table(), $row); // phpcs:ignore WordPress.DB

        return (int) $wpdb->insert_id;
    }

    /**
     * Paginated, filtered listing.
     *
     * @param array{verdict?: string, event_type?: string, form_id?: int, search?: string,
     *              from?: string, to?: string, page?: int, per_page?: int, order?: string} $args
     *
     * @return array{items: array<int, object>, total: int}
     */
    public static function list(array $args = []): array
    {
        global $wpdb;

        $per = min(max((int) ($args['per_page'] ?? 20), 1), 200);
        $page = max((int) ($args['page'] ?? 1), 1);
        $offset = ($page - 1) * $per;

        [$where, $params] = self::buildWhere($args);
        $order = ($args['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $table = self::table();
        $items = $wpdb->get_results(
            $params === []
                ? "SELECT * FROM {$table} ORDER BY id {$order} LIMIT {$per} OFFSET {$offset}" // phpcs:ignore WordPress.DB
                : $wpdb->prepare("SELECT * FROM {$table} WHERE 1=1 {$where} ORDER BY id {$order} LIMIT %d OFFSET %d", [...$params, $per, $offset]) // phpcs:ignore WordPress.DB
        );
        $total = (int) $wpdb->get_var(
            $params === []
                ? "SELECT COUNT(*) FROM {$table}" // phpcs:ignore WordPress.DB
                : $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE 1=1 {$where}", $params) // phpcs:ignore WordPress.DB
        );

        return ['items' => $items ?: [], 'total' => $total];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function buildWhere(array $args): array
    {
        global $wpdb;

        $where  = '';
        $params = [];

        if (!empty($args['verdict'])) {
            $where   .= ' AND verdict = %s';
            $params[] = (string) $args['verdict'];
        }
        if (!empty($args['event_type'])) {
            $where   .= ' AND event_type = %s';
            $params[] = (string) $args['event_type'];
        }
        if (!empty($args['form_id'])) {
            $where   .= ' AND form_id = %d';
            $params[] = (int) $args['form_id'];
        }
        if (!empty($args['from'])) {
            $where   .= ' AND created_at >= %s';
            $params[] = gmdate('Y-m-d 00:00:00', strtotime((string) $args['from']) ?: time());
        }
        if (!empty($args['to'])) {
            $where   .= ' AND created_at <= %s';
            $params[] = gmdate('Y-m-d 23:59:59', strtotime((string) $args['to']) ?: time());
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where   .= ' AND (ip LIKE %s OR user_agent LIKE %s OR reasons LIKE %s OR payload LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    public static function get(int $id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB

        return $row ?: null;
    }

    /**
     * Delete events older than N days (retention / GDPR). Returns rows deleted.
     */
    public static function purgeOlderThan(int $days): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        return (int) $wpdb->query( // phpcs:ignore WordPress.DB
            $wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff)
        );
    }

    public static function truncate(): int
    {
        global $wpdb;

        return (int) $wpdb->query('TRUNCATE TABLE ' . self::table()); // phpcs:ignore WordPress.DB
    }

    public static function count(?string $verdict = null): int
    {
        global $wpdb;

        $table = self::table();
        if ($verdict !== null) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE verdict = %s", $verdict)); // phpcs:ignore WordPress.DB
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB
    }

    public static function countToday(?string $verdict = null): int
    {
        global $wpdb;

        $start = gmdate('Y-m-d 00:00:00');
        $table = self::table();
        if ($verdict !== null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND verdict = %s",
                $start,
                $verdict
            )); // phpcs:ignore WordPress.DB
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $start)); // phpcs:ignore WordPress.DB
    }

    /**
     * Recent events for dashboards.
     *
     * @return array<int, object>
     */
    public static function recent(int $limit = 10): array
    {
        global $wpdb;

        $limit = min(max($limit, 1), 100);

        return $wpdb->get_results( // phpcs:ignore WordPress.DB
            'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT ' . $limit
        ) ?: [];
    }

    /**
     * Distinct event types present in the registry (for filter dropdowns).
     *
     * @return array<int, string>
     */
    public static function eventTypes(): array
    {
        global $wpdb;

        /** @var array<int, string>|null $types */
        $types = $wpdb->get_col('SELECT DISTINCT event_type FROM ' . self::table() . ' ORDER BY event_type'); // phpcs:ignore WordPress.DB

        return $types ?: [];
    }

    /**
     * CSV export stream helper (returns rows as arrays).
     *
     * @param array<string, mixed> $filter
     *
     * @return iterable<int, array<int, string>>
     */
    public static function exportCsv(array $filter = []): iterable
    {
        yield [
            'ID', 'Date (UTC)', 'Form ID', 'IP', 'Country', 'Type', 'Verdict',
            'Reasons', 'User Agent', 'Referrer', 'Payload',
        ];

        $page = 1;
        do {
            $batch = self::list(['page' => $page, 'per_page' => 200, ...$filter]);
            foreach ($batch['items'] as $row) {
                yield [
                    (string) $row->id,
                    (string) $row->created_at,
                    (string) $row->form_id,
                    (string) $row->ip,
                    (string) $row->country,
                    (string) $row->event_type,
                    (string) $row->verdict,
                    implode(' | ', array_map('strval', json_decode((string) $row->reasons, true) ?: [])),
                    (string) $row->user_agent,
                    (string) $row->referrer,
                    (string) $row->payload,
                ];
            }
            $page++;
        } while (count($batch['items']) === 200);
    }
}
