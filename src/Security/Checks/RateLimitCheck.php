<?php
/**
 * Rate limiter (per IP, storage-side atomic counter).
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;
use CF7Sec\Support\Ip;

defined('ABSPATH') || exit;

/**
 * Counts submissions per IP inside a 60-second window. Exceeding the limit
 * flags the submission and registers a strike with the BanManager (handled by
 * the pipeline).
 *
 * The counter is incremented *by the storage layer*: `wp_cache_incr()` when a
 * persistent object cache is present, otherwise a single atomic
 * `UPDATE ... option_value = option_value + 1` on the option row that backs
 * the counter. The window itself is owned by the storage too — the row expires
 * on its own, so PHP never resets it.
 */
final class RateLimitCheck implements CheckInterface
{
    /** Length of the counting window, in seconds. */
    public const WINDOW_SECONDS = 60;

    /** Cache group for the persistent-object-cache fast path. */
    private const CACHE_GROUP = 'cf7sec_rate_limit';

    public function id(): string
    {
        return 'rate_limit';
    }

    public function label(): string
    {
        return __('Rate limiting', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('rate_limit');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $max   = max(1, (int) $context->settings->get('checks.rate_limit.max_per_minute', 20));
        $key   = self::key($context->ip);
        $count = self::increment($key);

        if (self::exceeds($count, $max)) {
            return CheckResult::fail(
                $this->id(),
                sprintf(
                    __('%1$d submissions in the last 60 s (limit: %2$d).', 'spam-checker-for-contact-form-7'),
                    $count,
                    $max
                ),
                'rate_limit',
                ['count' => $count, 'max' => $max, 'window' => self::WINDOW_SECONDS]
            );
        }

        return CheckResult::pass($this->id(), ['count' => $count, 'max' => $max]);
    }

    /**
     * Counter key for an IP address.
     */
    public static function key(string $ip): string
    {
        return 'cf7sec_rl_' . substr(Ip::hash($ip), 0, 32);
    }

    /**
     * Pure limit decision — kept storage-free so the boundary stays testable
     * outside WordPress.
     */
    public static function exceeds(int $count, int $max): bool
    {
        return $count > $max;
    }

    /**
     * Atomically count one more submission for $key and return the new total.
     *
     * Concurrent submissions can no longer lose each other's increment: the
     * read-modify-write that used to happen here in PHP is replaced by an
     * increment executed by the storage layer.
     */
    public static function increment(string $key, int $window = self::WINDOW_SECONDS): int
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::CACHE_GROUP, $window);
            $count = wp_cache_incr($key, 1, self::CACHE_GROUP);
            if (is_int($count)) {
                return $count;
            }
            // Backend refused to increment — fall through to the database path.
        }

        return self::incrementOption($key, $window);
    }

    /**
     * Database-backed atomic increment.
     *
     * The counter lives in the option row that backs a transient, so WordPress
     * expires it for us: `set_transient()` only runs when the previous window
     * is gone, and every other submission is an atomic SQL increment.
     *
     * Known limit: two submissions that open the *same* fresh window at the
     * same moment can make one of them count one hit less. Every increment
     * inside an already-open window is exact, so the threshold can no longer
     * fire systematically late the way the previous PHP-side counter did.
     */
    private static function incrementOption(string $key, int $window): int
    {
        global $wpdb;

        $row = '_transient_' . $key;

        if (false === get_transient($key)) {
            // Opens (or re-opens) the window. Concurrent callers agree on the
            // same starting point, so a lost race cannot drop a later hit.
            set_transient($key, 0, $window);
        }

        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $row
        ));

        // The raw write bypasses the option cache; drop both views of the row.
        wp_cache_delete($row, 'options');
        wp_cache_delete($key, 'transient');

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $row
        ));
    }
}
