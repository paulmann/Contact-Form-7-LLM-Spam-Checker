<?php
/**
 * REST API controller (namespace cf7sec/v1).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Admin;

use CF7Sec\Plugin;
use CF7Sec\Services\Logger;
use CF7Sec\Settings;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\GeoCache;
use CF7Sec\Storage\IpRuleRepository;
use CF7Sec\Support\Ip;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * All endpoints require `manage_options` and a valid `X-WP-Nonce`.
 */
final class RestController
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        $ns = \CF7SEC_REST_NS;

        register_rest_route($ns, '/settings', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'getSettings'], 'permission_callback' => [$this, 'authorize']],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveSettings'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/test/llm', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'testLlm'], 'permission_callback' => [$this, 'authorize']],
        ]);
        // Backwards-compatible alias of /test/llm.
        register_rest_route($ns, '/test/deepseek', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'testLlm'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/test/abuseipdb', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'testAbuseIpDb'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/test/email', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'testEmail'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/stats', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'stats'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/health', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'health'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/events', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listEvents'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/events/export', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'exportEvents'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/events/clear', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'clearEvents'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/events/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'getEvent'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/ip-rules', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listIpRules'], 'permission_callback' => [$this, 'authorize']],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'addIpRule'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/ip-rules/export', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'exportIpRules'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/ip-rules/bulk-delete', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'bulkDeleteIpRules'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/ip-rules/(?P<ip>[a-fA-F0-9\.:]+)', [
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteIpRule'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/ip-lookup', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'ipLookup'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/counters/reset', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'resetCounters'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/settings/reset', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'resetSettings'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/geo/clear', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'clearGeoCache'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/export', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'exportSettings'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/import', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'importSettings'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/logs', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'debugLog'], 'permission_callback' => [$this, 'authorize']],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteDebugLog'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/logs/dates', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'debugLogDates'], 'permission_callback' => [$this, 'authorize']],
        ]);
        register_rest_route($ns, '/cleanup', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'cleanup'], 'permission_callback' => [$this, 'authorize']],
        ]);
    }

    public function authorize(): bool
    {
        return current_user_can(AdminMenu::CAPABILITY);
    }

    /* ------------------------------------------------------------------
     * Settings
     * ----------------------------------------------------------------*/

    public function getSettings(): WP_REST_Response
    {
        $all = $this->plugin->settings->all();

        // Mask secrets for transport; JS shows a placeholder input.
        $all['abuseipdb']['api_key'] = Settings::maskSecret((string) ($all['abuseipdb']['api_key'] ?? ''));
        foreach ((array) ($all['llm']['api_keys'] ?? []) as $pid => $key) {
            $all['llm']['api_keys'][$pid] = Settings::maskSecret((string) $key);
        }
        unset($all['internal']);

        return new WP_REST_Response(['settings' => $all], 200);
    }

    public function saveSettings(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = (array) $request->get_params();
        }

        $sanitized = $this->plugin->settings->sanitize($input);
        $this->plugin->settings->replace($sanitized);

        return $this->getSettings();
    }

    public function exportSettings(): WP_REST_Response
    {
        $all = $this->plugin->settings->all();
        unset($all['internal']);
        // Secrets ARE included — the export is a full backup for the admin.
        $payload = [
            'plugin'      => 'contact-form-7-spam-checker',
            'version'     => CF7SEC_VERSION,
            'exported_at' => gmdate('c'),
            'site'        => home_url(),
            'settings'    => $all,
        ];

        $response = new WP_REST_Response(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="cf7sec-settings-' . gmdate('Ymd-His') . '.json"');

        return $response;
    }

    public function importSettings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params  = $request->get_json_params();
        $payload = is_array($params) && isset($params['settings']) ? $params : null;

        if ($payload === null) {
            $raw = (string) $request->get_param('file_content');
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return new WP_Error('cf7sec_import', __('Invalid JSON payload.', 'contact-form-7-spam-checker'), ['status' => 400]);
            }
            $payload = $decoded;
        }

        if (($payload['plugin'] ?? '') !== 'contact-form-7-spam-checker' || !isset($payload['settings']) || !is_array($payload['settings'])) {
            return new WP_Error('cf7sec_import', __('This file was not exported by CF7 Spam Checker Pro.', 'contact-form-7-spam-checker'), ['status' => 400]);
        }

        $sanitized = $this->plugin->settings->sanitize($payload['settings']);
        $this->plugin->settings->replace($sanitized);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /* ------------------------------------------------------------------
     * Integration self-tests
     * ----------------------------------------------------------------*/

    public function testLlm(): WP_REST_Response
    {
        $result = $this->plugin->llm->test();
        if (is_wp_error($result)) {
            return new WP_REST_Response(['ok' => false, 'message' => $result->get_error_message()], 200);
        }

        return new WP_REST_Response($result, 200);
    }

    public function testAbuseIpDb(): WP_REST_Response|WP_Error
    {
        $result = $this->plugin->abuseipdb->test();
        if (is_wp_error($result)) {
            return new WP_REST_Response(['ok' => false, 'message' => $result->get_error_message()], 200);
        }

        return new WP_REST_Response($result, 200);
    }

    public function testEmail(WP_REST_Request $request): WP_REST_Response
    {
        $to = sanitize_email((string) $request->get_param('email'));
        if ($to === '') {
            $to = (string) $this->plugin->settings->get('notifications.email', '') ?: (string) get_option('admin_email');
        }

        $sent = $this->plugin->mailer->sendTest($to);

        return new WP_REST_Response([
            'ok'      => $sent,
            'message' => $sent
                ? __('Test e-mail sent.', 'contact-form-7-spam-checker')
                : __('wp_mail() failed. Check your server mail configuration.', 'contact-form-7-spam-checker'),
        ], 200);
    }

    public function ipLookup(WP_REST_Request $request): WP_REST_Response
    {
        $ip = Ip::valid((string) $request->get_param('ip'));
        if ($ip === '') {
            return new WP_REST_Response(['ok' => false, 'message' => __('Invalid IP address.', 'contact-form-7-spam-checker')], 200);
        }

        $result = $this->plugin->abuseipdb->lookup($ip, (bool) $request->get_param('refresh'));

        if (is_wp_error($result)) {
            return new WP_REST_Response(['ok' => false, 'message' => $result->get_error_message()], 200);
        }

        return new WP_REST_Response(['ok' => true, 'data' => $result], 200);
    }

    /* ------------------------------------------------------------------
     * Statistics & health
     * ----------------------------------------------------------------*/

    public function stats(WP_REST_Request $request): WP_REST_Response
    {
        $days = min(max((int) $request->get_param('days') ?: 30, 1), 365);

        return new WP_REST_Response([
            'series' => DailyStats::series($days),
            'totals' => DailyStats::totals(),
            'today'  => [
                'processed' => DailyStats::series(1)['processed'][0] ?? 0,
                'blocked'   => DailyStats::series(1)['blocked'][0] ?? 0,
            ],
            'bans'      => IpRuleRepository::countActiveBans(),
            'whitelist' => IpRuleRepository::countWhitelist(),
            'registry'  => EventsRepository::count(),
            'blocked_registry' => EventsRepository::count(EventsRepository::VERDICT_BLOCKED),
        ], 200);
    }

    public function health(): WP_REST_Response
    {
        global $wpdb;

        $tables = ['cf7sec_events', 'cf7sec_ip_rules', 'cf7sec_geo', 'cf7sec_daily'];
        $missing = [];
        foreach ($tables as $t) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $t));
            if ($exists === null) {
                $missing[] = $t;
            }
        }

        $logDir = \CF7Sec\Activator::logDir();

        return new WP_REST_Response([
            'php'            => PHP_VERSION,
            'php_ok'         => PHP_VERSION_ID >= 80300,
            'wp'             => get_bloginfo('version'),
            'cf7'            => defined('WPCF7_VERSION') ? (string) \WPCF7_VERSION : null,
            'cf7_ok'         => defined('WPCF7_VERSION'),
            'tables_ok'      => $missing === [],
            'tables_missing' => $missing,
            'log_dir'        => $logDir,
            'log_dir_ok'     => is_dir($logDir) && wp_is_writable($logDir),
            'cron_scheduled' => (bool) wp_next_scheduled(\CF7Sec\Cron::CLEANUP_HOOK),
            'abuse_budget'   => $this->plugin->abuseipdb->remainingBudget(),
        ], 200);
    }

    /* ------------------------------------------------------------------
     * Spam registry (events)
     * ----------------------------------------------------------------*/

    public function listEvents(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'verdict'    => (string) $request->get_param('verdict'),
            'event_type' => (string) $request->get_param('event_type'),
            'form_id'    => (int) $request->get_param('form_id'),
            'search'     => (string) $request->get_param('search'),
            'from'       => (string) $request->get_param('from'),
            'to'         => (string) $request->get_param('to'),
            'page'       => (int) $request->get_param('page') ?: 1,
            'per_page'   => (int) $request->get_param('per_page') ?: 20,
        ];

        $result = EventsRepository::list($args);

        return new WP_REST_Response([
            'items' => array_map([$this, 'formatEvent'], $result['items']),
            'total' => $result['total'],
            'page'  => $args['page'],
            'types' => EventsRepository::eventTypes(),
        ], 200);
    }

    private function formatEvent(object $row): array
    {
        return [
            'id'         => (int) $row->id,
            'created_at' => (string) $row->created_at,
            'local_time' => wp_date('Y-m-d H:i:s', strtotime((string) $row->created_at . ' UTC') ?: time()),
            'form_id'    => (int) $row->form_id,
            'ip'         => (string) $row->ip,
            'country'    => (string) $row->country,
            'event_type' => (string) $row->event_type,
            'verdict'    => (string) $row->verdict,
            'reasons'    => json_decode((string) $row->reasons, true) ?: [],
            'checks'     => json_decode((string) $row->checks, true) ?: [],
            'payload'    => json_decode((string) $row->payload, true) ?: null,
            'user_agent' => (string) $row->user_agent,
            'referrer'   => (string) $row->referrer,
        ];
    }

    public function getEvent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $row = EventsRepository::get((int) $request->get_param('id'));
        if ($row === null) {
            return new WP_Error('cf7sec_notfound', __('Event not found.', 'contact-form-7-spam-checker'), ['status' => 404]);
        }

        return new WP_REST_Response($this->formatEvent($row), 200);
    }

    public function clearEvents(): WP_REST_Response
    {
        EventsRepository::truncate();

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function exportEvents(WP_REST_Request $request): WP_REST_Response
    {
        $filter = [
            'verdict'    => (string) $request->get_param('verdict'),
            'event_type' => (string) $request->get_param('event_type'),
            'from'       => (string) $request->get_param('from'),
            'to'         => (string) $request->get_param('to'),
            'search'     => (string) $request->get_param('search'),
        ];

        $fh = fopen('php://temp', 'r+');
        foreach (EventsRepository::exportCsv(array_filter($filter)) as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $response = new WP_REST_Response($csv, 200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="cf7sec-registry-' . gmdate('Ymd-His') . '.csv"');

        return $response;
    }

    /* ------------------------------------------------------------------
     * IP rules
     * ----------------------------------------------------------------*/

    public function listIpRules(WP_REST_Request $request): WP_REST_Response
    {
        $result = IpRuleRepository::list([
            'type'     => (string) $request->get_param('type'),
            'search'   => (string) $request->get_param('search'),
            'page'     => (int) $request->get_param('page') ?: 1,
            'per_page' => (int) $request->get_param('per_page') ?: 20,
        ]);

        $now = time();
        $items = array_map(static function (object $r) use ($now): array {
            $expires = $r->expires_at !== null ? strtotime((string) $r->expires_at . ' UTC') : null;

            return [
                'ip'           => (string) $r->ip,
                'list_type'    => (string) $r->list_type,
                'reason'       => (string) $r->reason,
                'source'       => (string) $r->source,
                'strikes'      => (int) $r->strikes,
                'banned_count' => (int) $r->banned_count,
                'created_at'   => (string) $r->created_at,
                'expires_at'   => $r->expires_at,
                'permanent'    => $r->expires_at === null,
                'expired'      => $expires !== null && $expires <= $now,
            ];
        }, $result['items']);

        return new WP_REST_Response(['items' => $items, 'total' => $result['total'], 'page' => (int) $request->get_param('page') ?: 1], 200);
    }

    public function addIpRule(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ip   = Ip::valid((string) $request->get_param('ip'));
        $type = $request->get_param('type') === 'whitelist' ? IpRuleRepository::TYPE_WHITELIST : IpRuleRepository::TYPE_BAN;

        if ($ip === '') {
            return new WP_Error('cf7sec_badip', __('Invalid IP address.', 'contact-form-7-spam-checker'), ['status' => 400]);
        }
        if (Ip::isPrivate($ip) && $type === IpRuleRepository::TYPE_BAN) {
            return new WP_Error('cf7sec_privateip', __('Refusing to ban a private/reserved IP range — this would block your own server or LAN.', 'contact-form-7-spam-checker'), ['status' => 400]);
        }

        $reason   = sanitize_text_field((string) $request->get_param('reason'));
        $duration = (int) $request->get_param('duration_hours'); // 0 = permanent

        if ($type === IpRuleRepository::TYPE_WHITELIST) {
            $this->plugin->bans->whitelist($ip, $reason);

            return new WP_REST_Response(['ok' => true, 'ip' => $ip, 'type' => $type], 200);
        }

        $result = $this->plugin->bans->ban($ip, $reason !== '' ? $reason : __('Manual ban by administrator', 'contact-form-7-spam-checker'), 'manual', $duration === 0 ? 0 : $duration * 3600);

        return new WP_REST_Response(['ok' => true, 'ip' => $ip, 'type' => $type, ...$result], 200);
    }

    public function deleteIpRule(WP_REST_Request $request): WP_REST_Response
    {
        $ip = (string) $request->get_param('ip');
        IpRuleRepository::delete($ip);

        return new WP_REST_Response(['ok' => true, 'ip' => $ip], 200);
    }

    public function bulkDeleteIpRules(WP_REST_Request $request): WP_REST_Response
    {
        $ips = (array) $request->get_param('ips');
        $n   = IpRuleRepository::deleteMany($ips);

        return new WP_REST_Response(['ok' => true, 'deleted' => $n], 200);
    }

    public function exportIpRules(): WP_REST_Response
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['IP', 'Type', 'Reason', 'Source', 'Strikes', 'Created (UTC)', 'Expires (UTC)']);

        $page = 1;
        do {
            $batch = IpRuleRepository::list(['page' => $page, 'per_page' => 500]);
            foreach ($batch['items'] as $r) {
                fputcsv($fh, [(string) $r->ip, (string) $r->list_type, (string) $r->reason, (string) $r->source, (int) $r->strikes, (string) $r->created_at, (string) ($r->expires_at ?? 'permanent')]);
            }
            $page++;
        } while (count($batch['items']) === 500);

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $response = new WP_REST_Response($csv, 200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="cf7sec-ip-rules-' . gmdate('Ymd-His') . '.csv"');

        return $response;
    }

    /* ------------------------------------------------------------------
     * Maintenance
     * ----------------------------------------------------------------*/

    public function resetCounters(): WP_REST_Response
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'cf7sec_daily'); // phpcs:ignore WordPress.DB

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function resetSettings(): WP_REST_Response
    {
        $fresh = Settings::DEFAULTS;
        $fresh['internal'] = (array) $this->plugin->settings->get('internal', []);
        $this->plugin->settings->replace($fresh);

        return $this->getSettings();
    }

    public function clearGeoCache(): WP_REST_Response
    {
        GeoCache::truncate();

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function cleanup(): WP_REST_Response
    {
        \CF7Sec\Cron::run();

        return new WP_REST_Response([
            'ok'      => true,
            'message' => __('Maintenance run completed.', 'contact-form-7-spam-checker'),
        ], 200);
    }

    public function debugLog(WP_REST_Request $request): WP_REST_Response
    {
        $date  = (string) $request->get_param('date') ?: gmdate('Y-m-d');
        $lines = Logger::tail($date, 400);

        return new WP_REST_Response(['date' => $date, 'lines' => $lines, 'dates' => Logger::availableDates()], 200);
    }

    public function debugLogDates(): WP_REST_Response
    {
        return new WP_REST_Response(['dates' => Logger::availableDates()], 200);
    }

    public function deleteDebugLog(WP_REST_Request $request): WP_REST_Response
    {
        $ok = Logger::delete((string) $request->get_param('date'));

        return new WP_REST_Response(['ok' => $ok], 200);
    }
}
