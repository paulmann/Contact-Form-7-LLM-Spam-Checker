<?php
/**
 * Activation routines: DB schema, cron, legacy migration.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec;

defined('ABSPATH') || exit;

/**
 * Runs on plugin activation and on version upgrades.
 */
final class Activator
{
    public static function activate(): void
    {
        self::createTables();
        self::seedOptions();
        self::migrateSettingsSchema();
        self::migrateLegacyData();
        self::createLogDir();
        self::scheduleCleanup();

        // Flush rewrite rules so REST routes are available immediately.
        flush_rewrite_rules();
    }

    /**
     * Idempotent installer callable from Plugin::boot() on admin requests
     * (handles upgrades when the DB version constant changes).
     *
     * A regular plugin update from the Plugins screen never calls activate(),
     * so everything an upgrade needs must be reconciled here as well: the
     * schema (dbDelta), the legacy v3.x import and the cleanup cron
     * (defect P1, v4.1.1 — the imported ban list and the retention job were
     * silently skipped on update, letting the registry grow without bound).
     */
    public static function maybeUpgrade(): void
    {
        // Migrate FIRST on the raw option: a Settings object built before the
        // migration would carry the stale (pre-migration) array and clobber
        // the migrated values when written back (defect B2, v4.1.0).
        self::migrateSettingsSchema();

        $settings = new Settings(); // fresh read, post-migration
        $dbOk     = (int) $settings->get('internal.db_version', 0) === CF7SEC_DB_VERSION;
        $schemaOk = (int) $settings->get('internal.settings_schema', 0) === Settings::SCHEMA_VERSION;

        if (!$dbOk || !$schemaOk) {
            self::createTables();
            self::stampVersions($settings);
        }

        // Both of these are no-ops once they have run, so they stay outside the
        // version check and repair installs whose cron was never scheduled.
        self::migrateLegacyData();
        self::scheduleCleanup();
    }

    /**
     * Make sure the daily retention worker is scheduled. Safe to call on
     * every admin request.
     */
    public static function scheduleCleanup(): void
    {
        if (wp_next_scheduled(Cron::CLEANUP_HOOK)) {
            return;
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', Cron::CLEANUP_HOOK);
    }

    /**
     * Persist the current code/version stamps into the settings option.
     * Always re-reads the option through the given (or a fresh) Settings
     * object so concurrent migrations are never overwritten.
     */
    public static function stampVersions(?Settings $settings = null): void
    {
        $settings ??= new Settings();

        $all = $settings->all();
        $all['internal']['db_version']      = CF7SEC_DB_VERSION;
        $all['internal']['settings_schema'] = Settings::SCHEMA_VERSION;
        $settings->replace($all);
    }

    /**
     * v4.0 → v4.1: single-provider `deepseek` section becomes the
     * multi-provider `llm` gateway section.
     */
    public static function migrateSettingsSchema(): void
    {
        $stored = get_option(CF7SEC_OPTION, []);
        if (!is_array($stored) || $stored === []) {
            return;
        }
        $schema = (int) ($stored['internal']['settings_schema'] ?? 0);

        // Defensive: a partially migrated option (defect B2 scenario) may still
        // carry the dead `deepseek` section even with a current schema stamp.
        if ($schema >= Settings::SCHEMA_VERSION) {
            if (isset($stored['deepseek'])) {
                unset($stored['deepseek']);
                update_option(CF7SEC_OPTION, $stored, false);
            }

            return;
        }

        $migrated = Settings::migrateSchema($stored);
        $migrated['internal']['settings_schema'] = Settings::SCHEMA_VERSION;
        update_option(CF7SEC_OPTION, $migrated, false);
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        dbDelta(
            "CREATE TABLE {$p}cf7sec_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                country CHAR(2) NOT NULL DEFAULT '',
                event_type VARCHAR(32) NOT NULL DEFAULT '',
                verdict VARCHAR(16) NOT NULL DEFAULT 'blocked',
                reasons TEXT NULL,
                checks LONGTEXT NULL,
                payload LONGTEXT NULL,
                user_agent VARCHAR(512) NOT NULL DEFAULT '',
                referrer VARCHAR(1024) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY ip (ip),
                KEY event_type (event_type),
                KEY form_id (form_id),
                KEY verdict (verdict)
            ) $charset;"
        );

        dbDelta(
            "CREATE TABLE {$p}cf7sec_ip_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ip VARCHAR(45) NOT NULL,
                list_type VARCHAR(16) NOT NULL DEFAULT 'ban',
                reason VARCHAR(255) NOT NULL DEFAULT '',
                source VARCHAR(32) NOT NULL DEFAULT 'manual',
                strikes INT UNSIGNED NOT NULL DEFAULT 0,
                banned_count INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY ip (ip),
                KEY list_type (list_type),
                KEY expires_at (expires_at)
            ) $charset;"
        );

        dbDelta(
            "CREATE TABLE {$p}cf7sec_geo (
                ip VARCHAR(45) NOT NULL,
                country_code CHAR(2) NOT NULL DEFAULT '',
                country_name VARCHAR(100) NOT NULL DEFAULT '',
                isp VARCHAR(255) NOT NULL DEFAULT '',
                usage_type VARCHAR(64) NOT NULL DEFAULT '',
                domain VARCHAR(255) NOT NULL DEFAULT '',
                abuse_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                total_reports INT UNSIGNED NOT NULL DEFAULT 0,
                email_spam_reports INT UNSIGNED NOT NULL DEFAULT 0,
                is_whitelisted TINYINT(1) NOT NULL DEFAULT 0,
                is_tor TINYINT(1) NOT NULL DEFAULT 0,
                last_reported_at DATETIME NULL DEFAULT NULL,
                checked_at DATETIME NOT NULL,
                PRIMARY KEY  (ip),
                KEY checked_at (checked_at)
            ) $charset;"
        );

        dbDelta(
            "CREATE TABLE {$p}cf7sec_daily (
                d DATE NOT NULL,
                processed INT UNSIGNED NOT NULL DEFAULT 0,
                blocked INT UNSIGNED NOT NULL DEFAULT 0,
                llm_calls INT UNSIGNED NOT NULL DEFAULT 0,
                llm_spam INT UNSIGNED NOT NULL DEFAULT 0,
                abuse_calls INT UNSIGNED NOT NULL DEFAULT 0,
                abuse_flagged INT UNSIGNED NOT NULL DEFAULT 0,
                bans INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (d)
            ) $charset;"
        );
    }

    private static function seedOptions(): void
    {
        if (get_option(CF7SEC_OPTION, false) === false) {
            $defaults = Settings::DEFAULTS;
            $defaults['internal']['install_time']    = time();
            $defaults['internal']['db_version']      = CF7SEC_DB_VERSION;
            $defaults['internal']['settings_schema'] = Settings::SCHEMA_VERSION;
            add_option(CF7SEC_OPTION, $defaults, '', false);
        }
    }

    /**
     * One-time migration from the legacy v3.x storage:
     *  - option  `cf7sec_options`   -> new settings schema
     *  - file    ban_list.json      -> {prefix}cf7sec_ip_rules
     */
    private static function migrateLegacyData(): void
    {
        $settings = new Settings();
        if ((bool) $settings->get('internal.migrated_v4', false)) {
            return;
        }

        global $wpdb;

        $legacy = get_option('cf7sec_options', []);
        if (is_array($legacy) && $legacy !== []) {
            $all = $settings->all();

            $features = $legacy['security_features'] ?? [];
            $map      = [
                'ip_block'            => 'ip_rules',
                'time_check'          => 'time_check',
                'honeypot'            => 'honeypot',
                'sql_injection'       => 'sql_injection',
                'xss_protection'      => 'xss',
                'bot_detection'       => 'bot_ua',
                'rate_limiting'       => 'rate_limit',
                'language_validation' => 'language',
            ];
            foreach ($map as $legacyKey => $newKey) {
                if (isset($features[$legacyKey])) {
                    $all['checks'][$newKey]['enabled'] = (bool) $features[$legacyKey];
                }
            }
            if (isset($legacy['language_settings']['selected_language'])) {
                $all['checks']['language']['language'] = sanitize_key((string) $legacy['language_settings']['selected_language']);
            }
            if (isset($legacy['language_settings']['custom_fields'])) {
                $all['checks']['language']['fields'] = sanitize_text_field((string) $legacy['language_settings']['custom_fields']);
            }
            if (!empty($legacy['max_requests'])) {
                $all['checks']['rate_limit']['max_per_minute'] = (int) $legacy['max_requests'];
            }
            if (!empty($legacy['ban_threshold'])) {
                $all['checks']['rate_limit']['strike_threshold'] = (int) $legacy['ban_threshold'];
            }
            if (!empty($legacy['ban_duration'])) {
                $all['checks']['rate_limit']['ban_duration_hours'] = max(1, (int) round(((int) $legacy['ban_duration']) / 3600));
            }
            if (!empty($legacy['error_messages']) && is_array($legacy['error_messages'])) {
                foreach ($legacy['error_messages'] as $k => $v) {
                    if (isset($all['messages'][$k])) {
                        $all['messages'][$k] = sanitize_textarea_field((string) $v);
                    }
                }
            }
            $all['debug']['enabled'] = !empty($legacy['debug_mode']);

            $settings->replace($all);
        }

        // Import legacy ban list JSON (if present).
        $banFile = WP_CONTENT_DIR . '/cf7sec_logs/ban_list.json';
        if (is_readable($banFile)) {
            $bans = json_decode((string) file_get_contents($banFile), true);
            if (is_array($bans)) {
                $now = current_time('mysql', true);
                foreach ($bans as $ip => $info) {
                    $ip = (string) $ip;
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        continue;
                    }
                    $expires = null;
                    if (empty($info['is_permanent']) && !empty($info['expires_at'])) {
                        $expires = gmdate('Y-m-d H:i:s', (int) $info['expires_at']);
                        if (strtotime($expires) < time()) {
                            continue; // already expired
                        }
                    }
                    $wpdb->replace(
                        $wpdb->prefix . 'cf7sec_ip_rules',
                        [
                            'ip'         => $ip,
                            'list_type'  => 'ban',
                            'reason'     => mb_substr((string) ($info['reason'] ?? 'Migrated from v3'), 0, 250),
                            'source'     => 'migration',
                            'created_at' => !empty($info['banned_at'])
                                ? gmdate('Y-m-d H:i:s', (int) $info['banned_at'])
                                : $now,
                            'expires_at' => $expires,
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%s']
                    );
                }
            }
            @rename($banFile, $banFile . '.migrated');
        }

        $all = $settings->all();
        $all['internal']['migrated_v4'] = true;
        $settings->replace($all);
    }

    /**
     * Create the protected directory used for debug logs.
     */
    public static function createLogDir(): void
    {
        $uploads = wp_get_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . 'cf7sec';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }

    public static function logDir(): string
    {
        $uploads = wp_get_upload_dir();

        return trailingslashit($uploads['basedir']) . 'cf7sec';
    }
}
