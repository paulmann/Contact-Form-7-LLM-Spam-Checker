<?php
/**
 * Deactivation routines.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec;

defined('ABSPATH') || exit;

final class Deactivator
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Cron::CLEANUP_HOOK);
        flush_rewrite_rules();
    }
}
