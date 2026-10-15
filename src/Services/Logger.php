<?php
/**
 * File-based debug logger (admin toggle: Settings → Diagnostics).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Services;

use CF7Sec\Activator;
use CF7Sec\Settings;

defined('ABSPATH') || exit;

/**
 * Writes rotating daily debug logs into wp-content/uploads/cf7sec/,
 * a directory protected by .htaccess (deny-all).
 */
final class Logger
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('debug.enabled', false);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if (!$this->enabled()) {
            return;
        }

        $line = sprintf(
            "[%s] %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $message,
            $context === [] ? '' : wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $file = self::file();
        if ($file !== null) {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }

    public static function file(?string $date = null): ?string
    {
        $dir = Activator::logDir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return null;
        }

        return $dir . '/debug-' . ($date ?? gmdate('Y-m-d')) . '.log';
    }

    /**
     * Read the tail of a log file (admin viewer).
     *
     * @return array<int, string>
     */
    public static function tail(string $date, int $lines = 200): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        $file = self::file($date);
        if ($file === null || !is_readable($file)) {
            return [];
        }

        $content = (string) @file_get_contents($file);
        $all     = explode("\n", trim($content));

        return array_slice($all, -$lines);
    }

    /**
     * Available log dates (for the viewer dropdown).
     *
     * @return array<int, string>
     */
    public static function availableDates(): array
    {
        $dir = Activator::logDir();
        if (!is_dir($dir)) {
            return [];
        }

        $dates = [];
        foreach (glob($dir . '/debug-*.log') ?: [] as $file) {
            if (preg_match('/debug-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                $dates[] = $m[1];
            }
        }
        rsort($dates);

        return array_slice($dates, 0, 30);
    }

    public static function delete(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $file = self::file($date);

        return $file !== null && is_file($file) && @unlink($file);
    }

    /**
     * Rotate the current file when it exceeds $maxBytes.
     */
    public static function rotate(int $maxBytes = 5242880): void
    {
        $file = self::file();
        if ($file !== null && is_file($file) && filesize($file) > $maxBytes) {
            @rename($file, $file . '.1');
        }
    }
}
