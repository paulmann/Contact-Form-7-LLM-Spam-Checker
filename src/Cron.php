<?php
/**
 * Scheduled maintenance tasks.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec;

use CF7Sec\Services\Logger;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\GeoCache;
use CF7Sec\Storage\IpRuleRepository;

defined('ABSPATH') || exit;

/**
 * Daily WP-Cron worker: prunes expired bans, old events and stale caches.
 */
final class Cron
{
    public const CLEANUP_HOOK = 'cf7sec_daily_cleanup';

    public static function register(): void
    {
        add_action(self::CLEANUP_HOOK, [self::class, 'run']);
    }

    public static function run(): void
    {
        $settings = new Settings();

        // 1. Expired bans + stale strike rows.
        IpRuleRepository::purgeExpired();
        IpRuleRepository::purgeOldStrikes(max(1, (int) $settings->get('general.retention_days', 30)));

        // 2. Old events (retention policy / GDPR).
        $retention = max(1, (int) $settings->get('general.retention_days', 30));
        EventsRepository::purgeOlderThan($retention);

        // 3. Stale geo cache rows (older than cache_days + 30 days of slack).
        $cacheDays = (int) $settings->get('abuseipdb.cache_days', 7);
        GeoCache::purgeOlderThan($cacheDays + 30);

        // 4. Daily stats older than retention.
        DailyStats::purgeOlderThan($retention + 365);

        // 5. Debug log rotation.
        Logger::rotate(5 * 1024 * 1024);

        /**
         * Fires after the daily maintenance run completes.
         *
         * @since 4.0.0
         */
        do_action('cf7sec_after_cleanup');
    }
}
