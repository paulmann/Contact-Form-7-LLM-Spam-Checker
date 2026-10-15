<?php
/**
 * Settings repository — single source of truth for the plugin configuration.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec;

use CF7Sec\Services\Llm\ProviderCatalog;

defined('ABSPATH') || exit;

/**
 * Immutable-ish settings container with defaults, deep merge and sanitisation.
 *
 * All plugin code MUST read configuration through this class. The public
 * shape of the settings array is versioned and documented in
 * docs/ARCHITECTURE.md.
 */
final class Settings
{
    /**
     * Settings schema version — bump when section shapes change so that
     * Activator can migrate stored options on upgrade.
     */
    public const SCHEMA_VERSION = 2;

    /**
     * Full default schema.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'general' => [
            'protect_forms'        => 'all',   // all | selected
            'form_ids'             => [],
            'log_verdicts'         => 'blocked', // blocked | all | none
            'retention_days'       => 30,
            'show_admin_widget'    => true,
            'ip_header_mode'       => 'auto',  // auto | remote_only | cf | xff
        ],
        'notifications' => [
            'enabled'          => false,
            'email'            => '',
            'events'           => ['ip_banned', 'llm_error', 'abuse_error'],
            'throttle_minutes' => 60,
        ],
        'checks' => [
            'time_check' => [
                'enabled'          => true,
                'min_seconds'      => 3,
                'max_age_seconds'  => 3600,
                'skew_tolerance'   => 300,
            ],
            'honeypot' => [
                'enabled' => true,
            ],
            'ip_rules' => [
                'enabled' => true,
            ],
            'rate_limit' => [
                'enabled'                => true,
                'max_per_minute'         => 20,
                'strike_threshold'       => 50,
                'auto_ban'               => true,
                'ban_duration_hours'     => 1,
                'permanent_after_strikes' => 3,
            ],
            'sql_injection' => [
                'enabled' => true,
            ],
            'xss' => [
                'enabled' => true,
            ],
            'bot_ua' => [
                'enabled'        => true,
                'extra_keywords' => '',
            ],
            'link_spam' => [
                'enabled'   => true,
                'max_links' => 2,
            ],
            'keyword_spam' => [
                'enabled'  => true,
                'keywords' => "viagra\ncasino\nlottery\npayday loan\npharmacy\nreplica watch\nseo services\nbacklinks\ncripto\ncrypto invest\nроспись\nсрочно заработок",
            ],
            'language' => [
                'enabled'       => true,
                'language'      => 'english',
                'fields'        => 'family-name,given-name,middle-name,first-name,last-name,name,full-name,your-name,fio',
                'strict'        => false,
                'error_message' => '',
            ],
        ],
        'abuseipdb' => [
            'enabled'             => false,
            'api_key'             => '',
            'min_confidence'      => 50,
            'min_total_reports'   => 0,
            'min_email_spam_reports' => 0,
            'cache_days'          => 7,
            'max_age_days'        => 90,
            'auto_ban'            => false,
            'report_spam'         => false,
            'country_filter'      => [
                'enabled'   => false,
                'mode'      => 'block', // block | allow
                'countries' => '',      // comma separated ISO-3166-1 alpha-2
            ],
        ],
        'llm' => [
            'enabled'          => false,
            'provider'         => 'deepseek',
            'api_keys'         => [],   // providerId => secret key (masked in UI)
            'models'           => [],   // providerId => model id (free text)
            'base_urls'        => [],   // providerId => base URL override ('' = catalog default)
            'timeout'          => 20,
            'run_when_flagged' => false,
            'on_error'         => 'allow', // allow | block
            'ban_on_spam'      => false,
            'include_geo'      => true,
        ],
        'messages' => [
            'language_validation' => 'Please enter the %field_name% field using %language_name% characters only.',
            'sql_injection'       => 'Security check failed: suspicious SQL-like content detected in %field_name%.',
            'xss_attack'          => 'Security check failed: suspicious script content detected in %field_name%.',
            'bot_detected'        => 'Security check failed: automated submission detected.',
            'rate_limit'          => 'Security check failed: too many submissions from your IP address. Please try again in %minutes% minutes.',
            'honeypot'            => 'Security check failed: invalid form submission detected.',
            'time_check'          => 'Security check failed: the form was submitted too quickly. Please take your time.',
            'ip_blocked'          => 'Security check failed: your IP address is not allowed to submit this form.',
            'link_spam'           => 'Security check failed: too many links detected in %field_name%.',
            'keyword_spam'        => 'Security check failed: prohibited content detected in %field_name%.',
            'country_blocked'     => 'Security check failed: submissions from your region are not accepted.',
            'abuse_ip'            => 'Security check failed: your IP address has a poor reputation score.',
            'llm_spam'            => 'Security check failed: the submission was rejected by the automated analysis.',
        ],
        'debug' => [
            'enabled'      => false,
            'debug_output' => false,
        ],
        'uninstall' => [
            'keep_data' => false,
        ],
        'internal' => [
            'db_version'      => 0,
            'settings_schema' => 0,
            'migrated_v4'     => false,
            'install_time'    => 0,
        ],
    ];

    /** @var array<string, mixed> */
    private array $data;

    public function __construct()
    {
        $stored       = get_option(CF7SEC_OPTION, []);
        $this->data   = is_array($stored) ? self::deepMerge(self::DEFAULTS, $stored) : self::DEFAULTS;
    }

    /**
     * Full settings array (merged with defaults).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Read a dotted setting path, e.g. `get('checks.time_check.min_seconds')`.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function isEnabled(string $checkId): bool
    {
        return (bool) $this->get('checks.' . $checkId . '.enabled', false);
    }

    /**
     * Replace settings wholesale (already sanitised input expected).
     */
    public function replace(array $data): void
    {
        $this->data = self::deepMerge(self::DEFAULTS, $data);
        update_option(CF7SEC_OPTION, $this->data, false);
    }

    /**
     * Sanitise a raw (e.g. REST/POST) payload into a storable settings array.
     *
     * Partial payloads are merged over the current values. Secret keys
     * submitted as the mask placeholder are ignored (existing value kept).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $out = $this->data;

        /* ---- general ---- */
        if (isset($input['general']) && is_array($input['general'])) {
            $g = $input['general'];
            $out['general']['protect_forms']     = ($g['protect_forms'] ?? 'all') === 'selected' ? 'selected' : 'all';
            $out['general']['form_ids']          = array_values(array_unique(array_filter(
                array_map('absint', (array) ($g['form_ids'] ?? []))
            )));
            $out['general']['log_verdicts']      = in_array($g['log_verdicts'] ?? '', ['blocked', 'all', 'none'], true)
                ? $g['log_verdicts'] : 'blocked';
            $out['general']['retention_days']    = min(max((int) ($g['retention_days'] ?? 30), 1), 3650);
            $out['general']['show_admin_widget'] = !empty($g['show_admin_widget']);
            $out['general']['ip_header_mode']    = in_array($g['ip_header_mode'] ?? '', ['auto', 'remote_only', 'cf', 'xff'], true)
                ? $g['ip_header_mode'] : 'auto';
        }

        /* ---- notifications ---- */
        if (isset($input['notifications']) && is_array($input['notifications'])) {
            $n = $input['notifications'];
            $out['notifications']['enabled'] = !empty($n['enabled']);
            $email = sanitize_email((string) ($n['email'] ?? ''));
            $out['notifications']['email'] = is_email($email) ? $email : '';
            $allowed = ['ip_banned', 'spam_detected', 'llm_error', 'abuse_error', 'rate_limit_ban'];
            $out['notifications']['events'] = array_values(array_intersect(
                $allowed,
                array_map('sanitize_key', (array) ($n['events'] ?? []))
            ));
            $out['notifications']['throttle_minutes'] = min(max((int) ($n['throttle_minutes'] ?? 60), 1), 1440);
        }

        /* ---- checks ---- */
        if (isset($input['checks']) && is_array($input['checks'])) {
            $c = $input['checks'];

            $out['checks']['time_check'] = [
                'enabled'         => !empty($c['time_check']['enabled']),
                'min_seconds'     => min(max((int) ($c['time_check']['min_seconds'] ?? 3), 0), 300),
                'max_age_seconds' => min(max((int) ($c['time_check']['max_age_seconds'] ?? 3600), 60), 604800),
                'skew_tolerance'  => min(max((int) ($c['time_check']['skew_tolerance'] ?? 300), 0), 86400),
            ];
            $out['checks']['honeypot']['enabled']   = !empty($c['honeypot']['enabled']);
            $out['checks']['ip_rules']['enabled']   = !empty($c['ip_rules']['enabled']);
            $out['checks']['rate_limit'] = [
                'enabled'                 => !empty($c['rate_limit']['enabled']),
                'max_per_minute'          => min(max((int) ($c['rate_limit']['max_per_minute'] ?? 20), 1), 10000),
                'strike_threshold'        => min(max((int) ($c['rate_limit']['strike_threshold'] ?? 50), 1), 10000),
                'auto_ban'                => !empty($c['rate_limit']['auto_ban']),
                'ban_duration_hours'      => min(max((int) ($c['rate_limit']['ban_duration_hours'] ?? 1), 1), 8760),
                'permanent_after_strikes' => min(max((int) ($c['rate_limit']['permanent_after_strikes'] ?? 3), 1), 100),
            ];
            $out['checks']['sql_injection']['enabled'] = !empty($c['sql_injection']['enabled']);
            $out['checks']['xss']['enabled']           = !empty($c['xss']['enabled']);
            $out['checks']['bot_ua'] = [
                'enabled'        => !empty($c['bot_ua']['enabled']),
                'extra_keywords' => sanitize_textarea_field((string) ($c['bot_ua']['extra_keywords'] ?? '')),
            ];
            $out['checks']['link_spam'] = [
                'enabled'   => !empty($c['link_spam']['enabled']),
                'max_links' => min(max((int) ($c['link_spam']['max_links'] ?? 2), 0), 100),
            ];
            $out['checks']['keyword_spam'] = [
                'enabled'  => !empty($c['keyword_spam']['enabled']),
                'keywords' => sanitize_textarea_field((string) ($c['keyword_spam']['keywords'] ?? '')),
            ];
            $out['checks']['language'] = [
                'enabled'       => !empty($c['language']['enabled']),
                'language'      => array_key_exists($c['language']['language'] ?? '', Languages::REGEX)
                    ? (string) $c['language']['language'] : 'russian',
                'fields'        => sanitize_text_field((string) ($c['language']['fields'] ?? '')),
                'strict'        => !empty($c['language']['strict']),
                'error_message' => sanitize_textarea_field((string) ($c['language']['error_message'] ?? '')),
            ];
        }

        /* ---- abuseipdb ---- */
        if (isset($input['abuseipdb']) && is_array($input['abuseipdb'])) {
            $a = $input['abuseipdb'];
            $out['abuseipdb']['enabled']           = !empty($a['enabled']);
            $out['abuseipdb']['api_key']           = $this->sanitizeSecret($a['api_key'] ?? '', $this->data['abuseipdb']['api_key'], 128);
            $out['abuseipdb']['min_confidence']    = min(max((int) ($a['min_confidence'] ?? 50), 0), 100);
            $out['abuseipdb']['min_total_reports'] = min(max((int) ($a['min_total_reports'] ?? 0), 0), 100000);
            $out['abuseipdb']['min_email_spam_reports'] = min(max((int) ($a['min_email_spam_reports'] ?? 0), 0), 10000);
            $out['abuseipdb']['cache_days']        = min(max((int) ($a['cache_days'] ?? 7), 0), 90);
            $out['abuseipdb']['max_age_days']      = min(max((int) ($a['max_age_days'] ?? 90), 1), 365);
            $out['abuseipdb']['auto_ban']          = !empty($a['auto_ban']);
            $out['abuseipdb']['report_spam']       = !empty($a['report_spam']);
            if (isset($a['country_filter']) && is_array($a['country_filter'])) {
                $out['abuseipdb']['country_filter'] = [
                    'enabled'   => !empty($a['country_filter']['enabled']),
                    'mode'      => ($a['country_filter']['mode'] ?? 'block') === 'allow' ? 'allow' : 'block',
                    'countries' => strtoupper(sanitize_text_field((string) ($a['country_filter']['countries'] ?? ''))),
                ];
            }
        }

        /* ---- llm (multi-provider gateway) ---- */
        if (isset($input['llm']) && is_array($input['llm'])) {
            $l = $input['llm'];
            $out['llm']['enabled'] = !empty($l['enabled']);
            $out['llm']['provider'] = ProviderCatalog::exists((string) ($l['provider'] ?? ''))
                ? (string) $l['provider']
                : (string) ($this->data['llm']['provider'] ?? 'deepseek');
            $out['llm']['timeout']  = min(max((int) ($l['timeout'] ?? 20), 5), 120);
            $out['llm']['run_when_flagged'] = !empty($l['run_when_flagged']);
            $out['llm']['on_error'] = ($l['on_error'] ?? 'allow') === 'block' ? 'block' : 'allow';
            $out['llm']['ban_on_spam']  = !empty($l['ban_on_spam']);
            $out['llm']['include_geo']  = !empty($l['include_geo']);

            foreach (ProviderCatalog::ids() as $pid) {
                if (isset($l['api_keys'][$pid])) {
                    $out['llm']['api_keys'][$pid] = $this->sanitizeSecret(
                        $l['api_keys'][$pid],
                        (string) ($this->data['llm']['api_keys'][$pid] ?? ''),
                        256
                    );
                }
                if (isset($l['models'][$pid])) {
                    $model = preg_replace('/[^A-Za-z0-9._:\-\/]/', '', (string) $l['models'][$pid]) ?? '';
                    $out['llm']['models'][$pid] = substr($model, 0, 128);
                }
                if (isset($l['base_urls'][$pid])) {
                    $url = trim((string) $l['base_urls'][$pid]);
                    if ($url === '') {
                        $out['llm']['base_urls'][$pid] = ''; // '' = use catalog default
                    } elseif (str_starts_with($url, 'https://') || str_starts_with($url, 'http://localhost') || str_starts_with($url, 'http://127.0.0.1')) {
                        $out['llm']['base_urls'][$pid] = untrailingslashit(esc_url_raw($url));
                    }
                }
            }
        }

        /* ---- messages ---- */
        if (isset($input['messages']) && is_array($input['messages'])) {
            foreach (self::DEFAULTS['messages'] as $key => $default) {
                if (isset($input['messages'][$key])) {
                    $out['messages'][$key] = sanitize_textarea_field((string) $input['messages'][$key]);
                }
            }
        }

        /* ---- debug / uninstall ---- */
        if (isset($input['debug']) && is_array($input['debug'])) {
            $out['debug']['enabled']      = !empty($input['debug']['enabled']);
            $out['debug']['debug_output'] = !empty($input['debug']['debug_output']);
        }
        if (isset($input['uninstall']) && is_array($input['uninstall'])) {
            $out['uninstall']['keep_data'] = !empty($input['uninstall']['keep_data']);
        }

        return $out;
    }

    /**
     * Masked placeholder shown in the UI instead of a real secret.
     */
    public const MASK = '••••••••••••';

    /**
     * Mask a secret for display: keep the last 4 characters.
     */
    public static function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        return self::MASK . substr($secret, -4);
    }

    private function sanitizeSecret(mixed $value, string $current, int $maxLen): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_contains($value, '•')) {
            return $current; // masked or untouched — keep the stored secret
        }

        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $value) ?? '', 0, $maxLen);
    }

    /**
     * Migrate stored settings from the v4.0 schema (single-provider
     * `deepseek` section) to the v4.1 multi-provider `llm` section.
     * Idempotent — safe to run on every upgrade.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public static function migrateSchema(array $stored): array
    {
        if (!isset($stored['deepseek']) || isset($stored['llm']['provider'])) {
            return $stored;
        }

        $d   = (array) $stored['deepseek'];
        $llm = [
            'enabled'          => !empty($d['enabled']),
            'provider'         => 'deepseek',
            'api_keys'         => [],
            'models'           => [],
            'base_urls'        => [],
            'timeout'          => (int) ($d['timeout'] ?? 20),
            'run_when_flagged' => !empty($d['run_when_flagged']),
            'on_error'         => ($d['on_error'] ?? 'allow') === 'block' ? 'block' : 'allow',
            'ban_on_spam'      => !empty($d['ban_on_spam']),
            'include_geo'      => !empty($d['include_geo'] ?? true),
        ];
        if (!empty($d['api_key'])) {
            $llm['api_keys']['deepseek'] = (string) $d['api_key'];
        }
        if (!empty($d['model'])) {
            $llm['models']['deepseek'] = (string) $d['model'];
        }
        if (!empty($d['base_url']) && $d['base_url'] !== 'https://api.deepseek.com') {
            $llm['base_urls']['deepseek'] = (string) $d['base_url'];
        }

        $stored['llm'] = array_merge($llm, is_array($stored['llm'] ?? null) ? (array) $stored['llm'] : []);
        unset($stored['deepseek']);

        return $stored;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
