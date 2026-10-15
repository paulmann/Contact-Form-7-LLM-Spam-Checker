<?php
/**
 * Stub of wp-admin/includes/upgrade.php for standalone tests: dbDelta()
 * becomes a no-op so Activator::createTables() can run without a database.
 *
 * @package spam-checker-for-contact-form-7
 */

if (!function_exists('dbDelta')) {
    /**
     * @param string|null $queries
     * @param bool        $execute
     *
     * @return array<int, string>
     */
    function dbDelta($queries = null, $execute = true): array
    {
        return [];
    }
}
