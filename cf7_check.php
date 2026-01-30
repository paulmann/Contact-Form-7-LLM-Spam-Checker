<?php

/**
 * Plugin Name: CF7 Advanced Security Pro
 * Plugin URI: https://deynekin.com/cf7-security
 * Description: Advanced spam protection for Contact Form 7 with language validation, IP management, and detailed logging
 * Version: 3.5.3
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Author: Mikhail Deynekin
 * Author URI: https://deynekin.com
 * License: GPL v2 or later
 * Text Domain: cf7-security-pro
 */

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('CF7SEC_VERSION', '3.5.3');
define('CF7SEC_DIR', plugin_dir_path(__FILE__));
define('CF7SEC_URL', plugin_dir_url(__FILE__));
define('CF7SEC_LOG_DIR', WP_CONTENT_DIR . '/cf7sec_logs/');
define('CF7SEC_HISTORY_FILE', CF7SEC_LOG_DIR . 'form_check_history.json');

/**
 * Main Plugin Class
 */
class CF7_Advanced_Security_Pro
{

    private string $options_name = 'cf7sec_options';
    private array $languages = [];
    private bool $debug_mode = false;
    private int $protected_forms = 0;
    private int $processed_submissions = 0;
    private array $settings = [];
    private array $error_messages = [];

    // Language validation settings - 20+ LANGUAGES
    private const LANGUAGE_REGEX = [
        'russian' => ['name' => 'Russian', 'regex' => '/^[\p{Cyrillic}\s\-\'\.]+$/u'],
        'english' => ['name' => 'English', 'regex' => '/^[A-Za-z\s\-\'\.]+$/u'],
        'spanish' => ['name' => 'Spanish', 'regex' => '/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\-\'\.]+$/u'],
        'french' => ['name' => 'French', 'regex' => '/^[A-Za-zÀÂÆÇÉÈÊËÎÏÔŒÙÛÜŸàâæçéèêëîïôœùûüÿ\s\-\'\.]+$/u'],
        'german' => ['name' => 'German', 'regex' => '/^[A-Za-zÄÖÜßäöü\s\-\'\.]+$/u'],
        'chinese' => ['name' => 'Chinese', 'regex' => '/^[\p{Han}\s]+$/u'],
        'japanese' => ['name' => 'Japanese', 'regex' => '/^[\p{Hiragana}\p{Katakana}\p{Han}\s]+$/u'],
        'arabic' => ['name' => 'Arabic', 'regex' => '/^[\p{Arabic}\s]+$/u'],
        'hindi' => ['name' => 'Hindi', 'regex' => '/^[\p{Devanagari}\s]+$/u'],
        'portuguese' => ['name' => 'Portuguese', 'regex' => '/^[A-Za-zÁÉÍÓÚáéíóúÃÕãõÇç\s\-\'\.]+$/u'],
        'italian' => ['name' => 'Italian', 'regex' => '/^[A-Za-zÀÈÉÌÒÓÙàèéìòóù\s\-\'\.]+$/u'],
        'korean' => ['name' => 'Korean', 'regex' => '/^[\p{Hangul}\s]+$/u'],
        'turkish' => ['name' => 'Turkish', 'regex' => '/^[A-Za-zÇĞİÖŞÜçğıöşü\s\-\'\.]+$/u'],
        'dutch' => ['name' => 'Dutch', 'regex' => '/^[A-Za-z\s\-\'\.]+$/u'],
        'polish' => ['name' => 'Polish', 'regex' => '/^[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż\s\-\'\.]+$/u'],
        'swedish' => ['name' => 'Swedish', 'regex' => '/^[A-Za-zÅÄÖåäö\s\-\'\.]+$/u'],
        'vietnamese' => ['name' => 'Vietnamese', 'regex' => '/^[A-Za-zÀÁÂÃÈÉÊÌÍÒÓÔÕÙÚÝàáâãèéêìíòóôõùúýĂăĐđĨĩŨũƠơƯư\s\-\'\.]+$/u'],
        'greek' => ['name' => 'Greek', 'regex' => '/^[\p{Greek}\s]+$/u'],
        'hebrew' => ['name' => 'Hebrew', 'regex' => '/^[\p{Hebrew}\s]+$/u'],
        'thai' => ['name' => 'Thai', 'regex' => '/^[\p{Thai}\s]+$/u'],
        'ukrainian' => ['name' => 'Ukrainian', 'regex' => '/^[\p{Cyrillic}\s\-\'\.ІіЇїЄєҐґ]+$/u'],
        'czech' => ['name' => 'Czech', 'regex' => '/^[A-Za-zÁČĎÉĚÍŇÓŘŠŤÚŮÝŽáčďéěíňóřšťúůýž\s\-\'\.]+$/u'],
    ];

    // Default name field patterns for language validation
    private const DEFAULT_NAME_FIELDS = [
        'family-name',
        'given-name',
        'middle-name',
        'first-name',
        'last-name',
        'name',
        'full-name',
        'your-name',
        'fio',
        'имя',
        'фамилия'
    ];

    // Security patterns
    private const SQL_INJECTION_PATTERNS = [
        '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|CREATE|ALTER|EXEC)\b/i',
        '/\b(OR\s+1=1|AND\s+1=1)\b/i',
        '/--|\/\*|\*\//',
        '/\b(SLEEP|BENCHMARK|WAITFOR)\s*\(/i',
        '/@@version|version\(\)/i',
        '/LOAD_FILE\s*\(|INTO\s+(OUTFILE|DUMPFILE)/i',
    ];

    private const XSS_PATTERNS = [
        '/<script[^>]*>.*?<\/script>/is',
        '/javascript:/i',
        '/on\w+\s*=/i',
        '/data:/i',
        '/<iframe[^>]*>.*?<\/iframe>/is',
        '/<object[^>]*>.*?<\/object>/is',
        '/<embed[^>]*>.*?<\/embed>/is',
        '/<applet[^>]*>.*?<\/applet>/is',
        '/<meta[^>]*refresh[^>]*>/i',
        '/expression\s*\(/i',
    ];

    private const BOT_USER_AGENTS = [
        // Keep this list comprehensive for the cleaning function to filter
        'bot',
        'crawl',
        'spider',
        'scrape',
        'curl',
        'wget',
        'python',
        'java',
        'php',
        'ruby',
        'perl',
        'go-http',
        'node',
        'axios',
        'request',
        'libwww',
        'zgrab',
        'masscan',
        'nmap',
        'sqlmap',
        'nikto',
        'httrack',
        'webcopy',
        'sitebulb',
        'screaming frog',
        'semrush',
        'ahrefs',
        'moz',
        'megaindex',
        'dotbot',
        'mj12bot',
        'ahrefsbot',
        'semrushbot',
        'rogerbot',
        'exabot',
        'gptbot',
        'chatgpt',
        'anthropic',
        'claude',
        'bingbot',
        'googlebot',
        'yandexbot',
        'baiduspider',
        'duckduckbot',
        'slurp',
        'teoma',
        'webcrawler',
        'searchbot',
        'indexer',
        'scanner',
        'harvester',
    ];

    // Rate limiting
    private const MAX_REQUESTS_PER_MINUTE = 20;
    private const BAN_THRESHOLD = 50;
    private const BAN_DURATION = 3600;

    // Default error messages
    private const DEFAULT_ERROR_MESSAGES = [
        'language_validation' => 'Please enter the %field_name% field using %language_name% characters only.',
        'sql_injection' => 'Security check failed: SQL injection attempt detected in %field_name%.',
        'xss_attack' => 'Security check failed: XSS attack attempt detected in %field_name%.',
        'bot_detected' => 'Security check failed: Automated submission detected.',
        'rate_limit' => 'Security check failed: Too many submissions from your IP address. Please wait %minutes% minutes.',
        'honeypot' => 'Security check failed: Invalid form submission detected.',
        'time_check' => 'Security check failed: Form submitted too quickly. Please take your time.',
        'ip_blocked' => 'Security check failed: Your IP address is blocked from submitting forms.',
        'link_spam' => 'Security check failed: Excessive links detected in %field_name%.',
        'keyword_spam' => 'Security check failed: Spam keywords detected in %field_name%.',
    ];

    public function __construct()
    {
        $this->languages = self::LANGUAGE_REGEX;
        $this->error_messages = self::DEFAULT_ERROR_MESSAGES;

        // Initialize plugin
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);

        // CF7 Hooks
        add_filter('wpcf7_spam', [$this, 'check_spam'], 5, 2);
        add_filter('wpcf7_form_hidden_fields', [$this, 'add_hidden_fields']);

        // Validation hooks for language checking
        add_filter('wpcf7_validate_text', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_text*', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_email', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_email*', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_tel', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_tel*', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_textarea', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_textarea*', [$this, 'validate_field'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_cf7sec_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_cf7sec_toggle_feature', [$this, 'ajax_toggle_feature']);
        add_action('wp_ajax_cf7sec_reset_counter', [$this, 'ajax_reset_counter']);
        add_action('wp_ajax_cf7sec_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_cf7sec_manual_ban', [$this, 'ajax_manual_ban']);
        add_action('wp_ajax_cf7sec_unban_ip', [$this, 'ajax_unban_ip']);
        add_action('wp_ajax_cf7sec_clear_history', [$this, 'ajax_clear_history']);
        add_action('wp_ajax_cf7sec_get_check_history', [$this, 'ajax_get_check_history']);
        add_action('wp_ajax_cf7sec_get_log_content', [$this, 'ajax_get_log_content']);
        add_action('wp_ajax_cf7sec_delete_log', [$this, 'ajax_delete_log']);
        add_action('wp_ajax_cf7sec_clear_debug_logs', [$this, 'ajax_clear_debug_logs']);

        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'dashboard_widget']);

        // Track form creation/deletion
        add_action('wpcf7_save_contact_form', [$this, 'update_forms_count']);
        add_action('before_delete_post', [$this, 'track_form_deletion']);


        // CF7 integration
        add_action('wpcf7_editor_panels', [$this, 'add_cf7_integration_panel']);

        // Debug output hook - added for frontend debug display
        add_filter('wpcf7_ajax_json_echo', [$this, 'add_debug_output_to_response'], 10, 2);

        // Add cleanup hook for old transients
        add_action('cf7sec_cleanup_transients', [$this, 'clear_old_transients']);

        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('cf7sec_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'cf7sec_cleanup_transients');
        }

        // Create log directory
        $this->create_log_dir();
    }

    /**
     * Initialize plugin with dependency checks
     * FIXED: Added proper initialization of processed_submissions counter
     */
    public function init(): void
    {
        // Check if Contact Form 7 is active
        if (!class_exists('WPCF7_ContactForm')) {
            if (is_admin()) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error">';
                    echo '<p><strong>CF7 Advanced Security Pro:</strong> Contact Form 7 plugin is required but not active. Please install and activate Contact Form 7.</p>';
                    echo '</div>';
                });
            }
            return;
        }

        $this->load_settings();
        $this->protected_forms = $this->get_protected_forms_count();

        // FIXED: Get processed_submissions directly from settings array after load_settings()
        $this->processed_submissions = (int)($this->settings['processed_submissions'] ?? 0);

        // Load custom error messages
        if (!empty($this->settings['error_messages'])) {
            $this->error_messages = array_merge($this->error_messages, $this->settings['error_messages']);
        }
    }

    /**
     * Create log directory and initialize history file with error handling
     * IMPROVED: Added better error handling and permissions check
     */
    private function create_log_dir(): void
    {
        if (!file_exists(CF7SEC_LOG_DIR)) {
            // Try to create directory with proper permissions
            if (!wp_mkdir_p(CF7SEC_LOG_DIR)) {
                error_log('CF7 Security Pro: Failed to create log directory: ' . CF7SEC_LOG_DIR);
                return;
            }

            // Add .htaccess for security
            $htaccess_path = CF7SEC_LOG_DIR . '.htaccess';
            if (!file_exists($htaccess_path)) {
                @file_put_contents($htaccess_path, "Order Deny,Allow\nDeny from all\n");
            }
        }

        // Initialize history file if not exists
        if (!file_exists(CF7SEC_HISTORY_FILE)) {
            @file_put_contents(CF7SEC_HISTORY_FILE, json_encode([], JSON_PRETTY_PRINT));

            // Set proper permissions
            @chmod(CF7SEC_HISTORY_FILE, 0644);
        }

        // Check if directory is writable
        if (!is_writable(CF7SEC_LOG_DIR)) {
            error_log('CF7 Security Pro: Log directory is not writable: ' . CF7SEC_LOG_DIR);
        }
    }

    /**
     * Clean up old debug transients to prevent database bloat
     * NEW: Maintenance function to clean old temporary data
     */
    public function clear_old_transients(): void
    {
        global $wpdb;

        // Delete transients older than 1 hour
        $time = time() - 3600;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            AND option_value < %d",
                '_transient_cf7sec_debug_%',
                $time
            )
        );
    }

    /**
     * Load settings from database
     * FIXED: Ensure all counters are properly loaded
     */
    private function load_settings(): void
    {
        $defaults = [
            // Security Features
            'security_features' => [
                'ip_block' => true,
                'time_check' => true,
                'honeypot' => true,
                'sql_injection' => true,
                'xss_protection' => true,
                'bot_detection' => true,
                'rate_limiting' => true,
                'language_validation' => true,
            ],

            // Language Settings
            'language_settings' => [
                'enabled' => true,
                'selected_language' => 'russian',
                'custom_fields' => implode(',', self::DEFAULT_NAME_FIELDS),
                'strict_mode' => false,
                'validation_error_message' => self::DEFAULT_ERROR_MESSAGES['language_validation'],
            ],

            // Error Messages
            'error_messages' => self::DEFAULT_ERROR_MESSAGES,

            // Debug Settings
            'debug_mode' => false,
            'debug_output' => false, // Whether to show debug info to users

            // Statistics - FIXED: Ensure all counters have default values
            'processed_submissions' => 0,
            'blocked_submissions' => 0,

            // Rate Limiting
            'max_requests' => self::MAX_REQUESTS_PER_MINUTE,
            'ban_threshold' => self::BAN_THRESHOLD,
            'ban_duration' => self::BAN_DURATION,
        ];

        $options = get_option($this->options_name, []);
        $this->settings = array_merge($defaults, $options);
        $this->debug_mode = (bool)($this->settings['debug_mode'] ?? false);

        // FIXED: Ensure processed_submissions is always set
        $this->processed_submissions = (int)($this->settings['processed_submissions'] ?? 0);
    }

    /**
     * Admin initialization
     */
    public function admin_init(): void
    {
        register_setting('cf7sec_settings', $this->options_name, [$this, 'sanitize_settings']);
    }

    /**
     * Add admin menu with separate pages
     */
    public function admin_menu(): void
    {
        // Main menu item
        add_menu_page(
            'CF7 Advanced Security',
            'CF7 Security',
            'manage_options',
            'cf7-security-dashboard',
            [$this, 'dashboard_page'],
            'dashicons-shield-alt',
            31
        );

        // Dashboard submenu (main page)
        add_submenu_page(
            'cf7-security-dashboard',
            'Security Dashboard',
            'Dashboard',
            'manage_options',
            'cf7-security-dashboard',
            [$this, 'dashboard_page']
        );

        // General Settings submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'General Settings',
            'General Settings',
            'manage_options',
            'cf7-security-general',
            [$this, 'general_settings_page']
        );

        // Security Features submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'Security Features',
            'Security Features',
            'manage_options',
            'cf7-security-features',
            [$this, 'security_features_page']
        );

        // Language Settings submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'Language Settings',
            'Language Settings',
            'manage_options',
            'cf7-security-language',
            [$this, 'language_settings_page']
        );

        // Error Messages submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'Error Messages',
            'Error Messages',
            'manage_options',
            'cf7-security-messages',
            [$this, 'error_messages_page']
        );

        // IP Management submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'IP Management',
            'IP Management',
            'manage_options',
            'cf7-security-ip',
            [$this, 'ip_management_page']
        );

        // Statistics submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'Statistics',
            'Statistics',
            'manage_options',
            'cf7-security-stats',
            [$this, 'statistics_page']
        );

        // Debug Mode submenu
        add_submenu_page(
            'cf7-security-dashboard',
            'Debug Mode',
            'Debug Mode',
            'manage_options',
            'cf7-security-debug',
            [$this, 'debug_mode_page']
        );
    }

    /**
     * Dashboard Page - Main overview
     * FIXED: Use local properties for real-time statistics display
     */
    public function dashboard_page(): void
    {
        // Get options from database
        $options = get_option($this->options_name, []);
        $features = $options['security_features'] ?? [];
        $lang_settings = $options['language_settings'] ?? [];

        // FIXED: Use local properties for real-time statistics
        $blocked_submissions = $options['blocked_submissions'] ?? 0;
        $processed_submissions = $this->processed_submissions;
        $success_rate = $processed_submissions > 0 ?
            round(($processed_submissions - $blocked_submissions) / $processed_submissions * 100, 1) : 100;

        // Get last 15 form check history
        $check_history = $this->get_form_check_history(15);
?>
        <div class="wrap cf7sec-dashboard">
            <h1>CF7 Advanced Security Pro Dashboard</h1>

            <!-- Overview Stats -->
            <div class="cf7sec-dashboard-stats">
                <div class="cf7sec-stat-card">
                    <div class="stat-icon">🛡️</div>
                    <div class="stat-content">
                        <h3>Protected Forms</h3>
                        <p class="stat-number"><?php echo $this->protected_forms; ?></p>
                        <p class="stat-desc">Total Contact Form 7 forms protected</p>
                    </div>
                </div>

                <div class="cf7sec-stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3>Processed Submissions</h3>
                        <p class="stat-number"><?php echo $processed_submissions; ?></p>
                        <p class="stat-desc">Total spam and legitimate checks</p>
                    </div>
                </div>

                <div class="cf7sec-stat-card">
                    <div class="stat-icon">🚫</div>
                    <div class="stat-content">
                        <h3>Blocked Spam</h3>
                        <p class="stat-number"><?php echo $blocked_submissions; ?></p>
                        <p class="stat-desc">Successfully prevented spam submissions</p>
                    </div>
                </div>

                <div class="cf7sec-stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <h3>Success Rate</h3>
                        <p class="stat-number"><?php echo $success_rate; ?>%</p>
                        <p class="stat-desc">Clean submissions percentage</p>
                    </div>
                </div>
            </div>

            <!-- Current Settings Overview -->
            <div class="cf7sec-settings-overview">
                <div class="postbox">
                    <h2 class="hndle">Current Security Settings</h2>
                    <div class="inside">
                        <div class="settings-grid">
                            <!-- General Settings -->
                            <div class="settings-section">
                                <h3>📋 General Settings</h3>
                                <ul class="settings-list">
                                    <li>
                                        <strong>Rate Limiting:</strong>
                                        <?php echo esc_html($options['max_requests'] ?? self::MAX_REQUESTS_PER_MINUTE); ?> requests/minute
                                    </li>
                                    <li>
                                        <strong>Ban Threshold:</strong>
                                        <?php echo esc_html($options['ban_threshold'] ?? self::BAN_THRESHOLD); ?> attacks
                                    </li>
                                    <li>
                                        <strong>Ban Duration:</strong>
                                        <?php
                                        $duration = ($options['ban_duration'] ?? self::BAN_DURATION) / 3600;
                                        echo $duration > 0 ? $duration . ' hours' : 'Permanent';
                                        ?>
                                    </li>
                                </ul>
                                <p class="section-description">
                                    Controls the basic spam protection parameters including rate limiting and IP blocking thresholds.
                                </p>
                                <a href="<?php echo admin_url('admin.php?page=cf7-security-general'); ?>" class="button button-small">
                                    Configure General Settings
                                </a>
                            </div>

                            <!-- Security Features -->
                            <div class="settings-section">
                                <h3>🔐 Security Features</h3>
                                <ul class="settings-list">
                                    <?php
                                    $security_features = [
                                        'ip_block' => 'IP Blocking',
                                        'time_check' => 'Time Check',
                                        'honeypot' => 'Honeypot Field',
                                        'sql_injection' => 'SQL Injection Protection',
                                        'xss_protection' => 'XSS Protection',
                                        'bot_detection' => 'Bot Detection',
                                        'rate_limiting' => 'Rate Limiting',
                                        'language_validation' => 'Language Validation',
                                    ];

                                    foreach ($security_features as $key => $label):
                                        $enabled = isset($features[$key]) ? $features[$key] : true;
                                    ?>
                                        <li>
                                            <span class="status-indicator <?php echo $enabled ? 'enabled' : 'disabled'; ?>"></span>
                                            <strong><?php echo esc_html($label); ?>:</strong>
                                            <?php echo $enabled ? 'Enabled' : 'Disabled'; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="section-description">
                                    Individual security modules that can be toggled on/off to customize protection levels.
                                </p>
                                <a href="<?php echo admin_url('admin.php?page=cf7-security-features'); ?>" class="button button-small">
                                    Configure Security Features
                                </a>
                            </div>

                            <!-- Language Settings -->
                            <div class="settings-section">
                                <h3>🌐 Language Settings</h3>
                                <ul class="settings-list">
                                    <li>
                                        <strong>Language Validation:</strong>
                                        <?php echo ($lang_settings['enabled'] ?? true) ? 'Enabled' : 'Disabled'; ?>
                                    </li>
                                    <li>
                                        <strong>Selected Language:</strong>
                                        <?php echo esc_html($this->languages[$lang_settings['selected_language'] ?? 'russian']['name'] ?? 'Russian'); ?>
                                    </li>
                                    <li>
                                        <strong>Strict Mode:</strong>
                                        <?php echo ($lang_settings['strict_mode'] ?? false) ? 'Enabled' : 'Disabled'; ?>
                                    </li>
                                    <li>
                                        <strong>Custom Fields:</strong>
                                        <?php
                                        $fields = $lang_settings['custom_fields'] ?? implode(',', self::DEFAULT_NAME_FIELDS);
                                        echo count(explode(',', $fields)) . ' fields configured';
                                        ?>
                                    </li>
                                </ul>
                                <p class="section-description">
                                    Validates name fields against selected language character sets to prevent mixed-language spam.
                                </p>
                                <a href="<?php echo admin_url('admin.php?page=cf7-security-language'); ?>" class="button button-small">
                                    Configure Language Settings
                                </a>
                            </div>

                            <!-- Debug Mode -->
                            <div class="settings-section">
                                <h3>🐛 Debug Mode</h3>
                                <ul class="settings-list">
                                    <li>
                                        <strong>Debug Mode:</strong>
                                        <?php echo $this->debug_mode ? 'Enabled' : 'Disabled'; ?>
                                    </li>
                                    <li>
                                        <strong>Debug Output:</strong>
                                        <?php echo ($options['debug_output'] ?? false) ? 'Enabled' : 'Disabled'; ?>
                                    </li>
                                    <li>
                                        <strong>Log Directory:</strong>
                                        <code><?php echo esc_html(CF7SEC_LOG_DIR); ?></code>
                                    </li>
                                    <li>
                                        <strong>Recent Logs:</strong>
                                        <?php
                                        $logs = glob(CF7SEC_LOG_DIR . 'debug_*.json');
                                        echo count($logs) . ' files';
                                        ?>
                                    </li>
                                </ul>
                                <p class="section-description">
                                    Detailed logging and debugging tools for troubleshooting and monitoring form submissions.
                                </p>
                                <a href="<?php echo admin_url('admin.php?page=cf7-security-debug'); ?>" class="button button-small">
                                    Configure Debug Mode
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Check History -->
            <div class="cf7sec-history-section">
                <div class="postbox">
                    <h2 class="hndle">
                        Recent Form Check History (Last 15 Submissions)
                        <span class="history-actions">
                            <button type="button" class="button button-small" id="refresh-history">
                                <span class="dashicons dashicons-update"></span> Refresh
                            </button>
                            <button type="button" class="button button-small" id="clear-history">
                                <span class="dashicons dashicons-trash"></span> Clear History
                            </button>
                        </span>
                    </h2>
                    <div class="inside">
                        <?php if (empty($check_history)): ?>
                            <p>No form check history available yet. History will appear here after form submissions.</p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped history-table">
                                <thead>
                                    <tr>
                                        <th width="15%">Time</th>
                                        <th width="10%">Form ID</th>
                                        <th width="15%">IP Address</th>
                                        <th width="10%">Result</th>
                                        <th width="40%">Reasons / Details</th>
                                        <th width="10%">Attempts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($check_history as $entry): ?>
                                        <tr class="history-row status-<?php echo strtolower($entry['result']); ?>">
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($entry['timestamp'])); ?></td>
                                            <td><code>#<?php echo esc_html($entry['form_id']); ?></code></td>
                                            <td>
                                                <code><?php echo esc_html($entry['ip']); ?></code>
                                                <?php if ($entry['attempts'] > 1): ?>
                                                    <span class="attempts-badge"><?php echo $entry['attempts']; ?>x</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="result-badge result-<?php echo strtolower($entry['result']); ?>">
                                                    <?php echo esc_html($entry['result']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($entry['reasons'])): ?>
                                                    <ul class="reasons-list">
                                                        <?php foreach ($entry['reasons'] as $reason): ?>
                                                            <li><?php echo esc_html($reason); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <span class="no-reasons">Clean submission</span>
                                                <?php endif; ?>

                                                <?php if (!empty($entry['attack_types'])): ?>
                                                    <div class="attack-types">
                                                        <strong>Attack types:</strong>
                                                        <?php echo implode(', ', array_map('esc_html', $entry['attack_types'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo esc_html($entry['attempts']); ?>
                                                <?php if ($entry['is_duplicate'] ?? false): ?>
                                                    <span class="dashicons dashicons-admin-users" title="Multiple attempts from same IP"></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="history-stats">
                                <div class="stat">
                                    <span class="stat-label">Total Records:</span>
                                    <span class="stat-value"><?php echo count($check_history); ?></span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Clean Submissions:</span>
                                    <span class="stat-value">
                                        <?php
                                        $clean = array_filter($check_history, fn($e) => $e['result'] === 'CLEAN');
                                        echo count($clean);
                                        ?>
                                    </span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Blocked Submissions:</span>
                                    <span class="stat-value">
                                        <?php
                                        $blocked = array_filter($check_history, fn($e) => $e['result'] === 'BLOCKED');
                                        echo count($blocked);
                                        ?>
                                    </span>
                                </div>
                                <div class="stat">
                                    <span class="stat-label">Repeat Attempts:</span>
                                    <span class="stat-value">
                                        <?php
                                        $repeats = array_filter($check_history, fn($e) => ($e['attempts'] ?? 1) > 1);
                                        echo count($repeats);
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="cf7sec-quick-actions">
                <div class="postbox">
                    <h2 class="hndle">Quick Actions</h2>
                    <div class="inside">
                        <div class="actions-grid">
                            <a href="<?php echo admin_url('admin.php?page=cf7-security-general'); ?>" class="action-card">
                                <span class="dashicons dashicons-admin-settings"></span>
                                <h3>General Settings</h3>
                                <p>Configure rate limiting, ban thresholds, and basic protection parameters</p>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=cf7-security-features'); ?>" class="action-card">
                                <span class="dashicons dashicons-shield"></span>
                                <h3>Security Features</h3>
                                <p>Enable/disable individual security modules and customize protection</p>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=cf7-security-language'); ?>" class="action-card">
                                <span class="dashicons dashicons-translation"></span>
                                <h3>Language Settings</h3>
                                <p>Set up language validation for name fields across 22+ languages</p>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=cf7-security-messages'); ?>" class="action-card">
                                <span class="dashicons dashicons-format-chat"></span>
                                <h3>Error Messages</h3>
                                <p>Customize all error messages shown to users</p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .cf7sec-dashboard {
                max-width: 1400px;
            }

            .cf7sec-dashboard-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }

            .cf7sec-stat-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 10px;
                padding: 25px;
                display: flex;
                align-items: center;
                gap: 20px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            }

            .cf7sec-stat-card .stat-icon {
                font-size: 40px;
                width: 60px;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border-radius: 50%;
            }

            .cf7sec-stat-card .stat-content h3 {
                margin: 0 0 10px;
                color: #333;
                font-size: 16px;
            }

            .cf7sec-stat-card .stat-number {
                font-size: 2.5em;
                font-weight: bold;
                margin: 5px 0;
                color: #2271b1;
            }

            .cf7sec-stat-card .stat-desc {
                margin: 0;
                color: #666;
                font-size: 14px;
                line-height: 1.4;
            }

            .cf7sec-settings-overview .settings-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .settings-section {
                background: #f8f9fa;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
            }

            .settings-section h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #2271b1;
                color: #333;
            }

            .settings-list {
                margin: 15px 0;
                padding: 0;
                list-style: none;
            }

            .settings-list li {
                margin: 8px 0;
                padding: 5px 0;
                border-bottom: 1px solid #eee;
                display: flex;
                align-items: center;
            }

            .settings-list li:last-child {
                border-bottom: none;
            }

            .status-indicator {
                display: inline-block;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                margin-right: 8px;
            }

            .status-indicator.enabled {
                background: #46b450;
            }

            .status-indicator.disabled {
                background: #dc3232;
            }

            .section-description {
                margin: 15px 0;
                color: #666;
                font-size: 14px;
                line-height: 1.5;
                padding: 10px;
                background: white;
                border-radius: 5px;
                border-left: 3px solid #2271b1;
            }

            .history-section .hndle {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .history-actions {
                display: flex;
                gap: 10px;
            }

            .history-table {
                margin-top: 10px;
            }

            .history-row.status-blocked {
                background: #f8d7da;
            }

            .history-row.status-clean {
                background: #d4edda;
            }

            .result-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }

            .result-badge.result-clean {
                background: #d4edda;
                color: #155724;
            }

            .result-badge.result-blocked {
                background: #f8d7da;
                color: #721c24;
            }

            .attempts-badge {
                display: inline-block;
                margin-left: 5px;
                padding: 1px 6px;
                background: #6c757d;
                color: white;
                border-radius: 10px;
                font-size: 11px;
                font-weight: bold;
            }

            .reasons-list {
                margin: 5px 0;
                padding-left: 20px;
            }

            .reasons-list li {
                margin: 3px 0;
                color: #721c24;
                font-size: 13px;
            }

            .no-reasons {
                color: #155724;
                font-style: italic;
            }

            .attack-types {
                margin-top: 5px;
                padding: 5px;
                background: #fff3cd;
                border-radius: 3px;
                font-size: 12px;
                color: #856404;
            }

            .history-stats {
                display: flex;
                gap: 20px;
                margin-top: 20px;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 5px;
                border: 1px solid #e0e0e0;
            }

            .history-stats .stat {
                text-align: center;
                flex: 1;
            }

            .history-stats .stat-label {
                display: block;
                font-size: 12px;
                color: #666;
                margin-bottom: 5px;
            }

            .history-stats .stat-value {
                display: block;
                font-size: 24px;
                font-weight: bold;
                color: #2271b1;
            }

            .cf7sec-quick-actions .actions-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .action-card {
                display: block;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                text-decoration: none;
                color: #333;
                transition: all 0.3s ease;
            }

            .action-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                border-color: #2271b1;
            }

            .action-card .dashicons {
                font-size: 32px;
                width: 32px;
                height: 32px;
                color: #2271b1;
                margin-bottom: 15px;
            }

            .action-card h3 {
                margin: 0 0 10px;
                color: #2271b1;
            }

            .action-card p {
                margin: 0;
                color: #666;
                font-size: 14px;
                line-height: 1.5;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Refresh history
                $('#refresh-history').on('click', function() {
                    var $button = $(this);
                    $button.prop('disabled', true).html('<span class="dashicons dashicons-update"></span> Refreshing...');

                    setTimeout(function() {
                        location.reload();
                    }, 500);
                });

                // Clear history
                $('#clear-history').on('click', function() {
                    if (confirm('Are you sure you want to clear all form check history? This action cannot be undone.')) {
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'cf7sec_clear_history',
                                nonce: '<?php echo wp_create_nonce("cf7sec_clear_history"); ?>'
                            },
                            beforeSend: function() {
                                $('#clear-history').prop('disabled', true)
                                    .html('<span class="dashicons dashicons-trash"></span> Clearing...');
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert(response.data.message || 'History cleared successfully');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else {
                                    alert('Failed to clear history: ' + (response.data || 'Unknown error'));
                                    $('#clear-history').prop('disabled', false)
                                        .html('<span class="dashicons dashicons-trash"></span> Clear History');
                                }
                            },
                            error: function() {
                                $('#clear-history').prop('disabled', false)
                                    .html('<span class="dashicons dashicons-trash"></span> Clear History');
                            }
                        });
                    }
                });
            });
        </script>
    <?php
    }

    /**
     * Error Messages Page
     */
    public function error_messages_page(): void
    {
        $options = get_option($this->options_name, []);
        $error_messages = $options['error_messages'] ?? self::DEFAULT_ERROR_MESSAGES;
    ?>
        <div class="wrap">
            <h1>Error Message Customization</h1>
            <p class="description">Customize all error messages shown to users when form validation fails. Use placeholders like %field_name%, %language_name%, %minutes% as needed.</p>

            <form method="post" action="options.php" class="cf7sec-settings-form">
                <?php settings_fields('cf7sec_settings'); ?>

                <div class="postbox">
                    <div class="inside">
                        <h2>Error Messages</h2>
                        <table class="form-table">
                            <?php foreach (self::DEFAULT_ERROR_MESSAGES as $key => $default): ?>
                                <tr>
                                    <th style="width: 200px; vertical-align: top;">
                                        <label for="error_<?php echo esc_attr($key); ?>">
                                            <?php
                                            $labels = [
                                                'language_validation' => 'Language Validation Error',
                                                'sql_injection' => 'SQL Injection Error',
                                                'xss_attack' => 'XSS Attack Error',
                                                'bot_detected' => 'Bot Detection Error',
                                                'rate_limit' => 'Rate Limit Error',
                                                'honeypot' => 'Honeypot Error',
                                                'time_check' => 'Time Check Error',
                                                'ip_blocked' => 'IP Blocked Error',
                                                'link_spam' => 'Link Spam Error',
                                                'keyword_spam' => 'Keyword Spam Error',
                                            ];
                                            echo esc_html($labels[$key] ?? ucfirst(str_replace('_', ' ', $key)));
                                            ?>
                                        </label>
                                        <p class="description" style="font-weight: normal;">
                                            Default: <?php echo esc_html($default); ?>
                                        </p>
                                    </th>
                                    <td>
                                        <textarea id="error_<?php echo esc_attr($key); ?>"
                                            name="cf7sec_options[error_messages][<?php echo esc_attr($key); ?>]"
                                            class="large-text" rows="3" style="width: 100%; max-width: 600px;"><?php
                                                                                                                echo esc_textarea($error_messages[$key] ?? $default);
                                                                                                                ?></textarea>
                                        <p class="description">
                                            <?php
                                            $descriptions = [
                                                'language_validation' => 'Shown when name field contains characters from wrong language. Placeholders: %field_name%, %language_name%',
                                                'sql_injection' => 'Shown when SQL injection attempt is detected. Placeholder: %field_name%',
                                                'xss_attack' => 'Shown when XSS attack attempt is detected. Placeholder: %field_name%',
                                                'bot_detected' => 'Shown when bot is detected by user agent analysis',
                                                'rate_limit' => 'Shown when rate limit is exceeded. Placeholder: %minutes%',
                                                'honeypot' => 'Shown when honeypot field is filled (bot detection)',
                                                'time_check' => 'Shown when form is submitted too quickly',
                                                'ip_blocked' => 'Shown when IP address is blocked',
                                                'link_spam' => 'Shown when excessive links are detected. Placeholder: %field_name%',
                                                'keyword_spam' => 'Shown when spam keywords are detected. Placeholder: %field_name%',
                                            ];
                                            echo esc_html($descriptions[$key] ?? '');
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>

                <?php submit_button('Save Error Messages', 'primary', 'submit', true); ?>
            </form>

            <div class="postbox" style="margin-top: 30px;">
                <div class="inside">
                    <h3>Available Placeholders</h3>
                    <ul>
                        <li><code>%field_name%</code> - The name of the field that failed validation</li>
                        <li><code>%language_name%</code> - The expected language name (e.g., "Russian", "English")</li>
                        <li><code>%minutes%</code> - Number of minutes to wait (for rate limiting)</li>
                        <li><code>%ip_address%</code> - Client's IP address</li>
                        <li><code>%bot_keyword%</code> - The bot keyword that was detected</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php
    }


    /**
     * Enhanced MAIN SPAM CHECK FUNCTION with improved error handling and detailed logging
     */
    public function check_spam(bool $spam, WPCF7_Submission $submission): bool
    {
        if ($spam) return $spam;

        // Increment processed submissions counter
        $this->increment_counter('processed_submissions');

        $options = get_option($this->options_name, []);
        $features = $options['security_features'] ?? [];
        $posted_data = $submission->get_posted_data();
        $form_id = $submission->get_contact_form()->id();
        $reasons = [];
        $attack_types = [];
        $detailed_reasons = [];

        // Start debug log if enabled
        $debug_data = $this->debug_mode ? [
            'timestamp' => date('c'),
            'form_id' => $form_id,
            'client_ip' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'posted_data' => $this->sanitize_posted_data_for_log($posted_data),
            'checks' => [],
            'security_features_enabled' => $features,
            'language_settings' => $options['language_settings'] ?? [],
            'error_messages' => $this->error_messages
        ] : null;

        // 1. Time-based check (honeypot)
        if ($features['time_check'] ?? true) {
            $client_time = (int)($posted_data['_cf7a_timestamp'] ?? 0);
            $server_time = time();

            if ($client_time > 0) {
                $time_diff = $server_time - $client_time;

                // Too fast submission (less than 3 seconds)
                if ($time_diff < 3) {
                    $spam = true;
                    $reason = 'Form submitted too quickly (' . $time_diff . 's) - human users typically take 3+ seconds';
                    $reasons[] = $reason;
                    $detailed_reasons[] = [
                        'type' => 'TIME_CHECK',
                        'message' => $reason,
                        'client_time' => $client_time,
                        'server_time' => $server_time,
                        'difference' => $time_diff,
                        'threshold' => 3
                    ];
                    $attack_types[] = 'TIME_ATTACK';
                }
                // Expired timestamp (more than 1 hour)
                if ($time_diff > 3600) {
                    $spam = true;
                    $reason = 'Form timestamp expired (' . $time_diff . 's old) - form was filled more than 1 hour ago';
                    $reasons[] = $reason;
                    $detailed_reasons[] = [
                        'type' => 'EXPIRED_FORM',
                        'message' => $reason,
                        'client_time' => $client_time,
                        'server_time' => $server_time,
                        'difference' => $time_diff,
                        'threshold' => 3600
                    ];
                    $attack_types[] = 'EXPIRED_FORM';
                }
            }

            if ($debug_data) {
                $debug_data['checks']['time_check'] = [
                    'client_time' => $client_time,
                    'server_time' => $server_time,
                    'difference' => $server_time - $client_time,
                    'threshold_fast' => 3,
                    'threshold_expired' => 3600,
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // 2. Honeypot check
        if (!$spam && ($features['honeypot'] ?? true)) {
            $honeypot_field = 'cf7_asc_hp_' . substr(md5((string)$form_id), 0, 8);
            if (!empty($posted_data[$honeypot_field])) {
                $spam = true;
                $reason = 'Honeypot field "' . $honeypot_field . '" was filled (bots often fill hidden fields)';
                $reasons[] = $reason;
                $detailed_reasons[] = [
                    'type' => 'HONEYPOT',
                    'message' => $reason,
                    'field' => $honeypot_field,
                    'value' => $posted_data[$honeypot_field],
                    'expected' => 'empty'
                ];
                $attack_types[] = 'HONEYPOT_TRIGGERED';
            }

            if ($debug_data) {
                $debug_data['checks']['honeypot'] = [
                    'field' => $honeypot_field,
                    'value' => $posted_data[$honeypot_field] ?? 'empty',
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // 3. IP blocking check
        if (!$spam && ($features['ip_block'] ?? true)) {
            $client_ip = $this->get_client_ip();
            $ban_list = $this->get_ban_list();

            if (isset($ban_list[$client_ip])) {
                $spam = true;
                $ban_info = $ban_list[$client_ip];
                $reason = 'IP address ' . $client_ip . ' is banned (since: ' . date('Y-m-d H:i', $ban_info['banned_at']) .
                    ', reason: ' . $ban_info['reason'] . ')';
                $reasons[] = $reason;
                $detailed_reasons[] = [
                    'type' => 'IP_BLOCKED',
                    'message' => $reason,
                    'ip' => $client_ip,
                    'banned_at' => $ban_info['banned_at'],
                    'expires_at' => $ban_info['expires_at'],
                    'is_permanent' => $ban_info['is_permanent'] ?? false,
                    'original_reason' => $ban_info['reason']
                ];
                $attack_types[] = 'BANNED_IP';
            }

            if ($debug_data) {
                $debug_data['checks']['ip_check'] = [
                    'ip' => $client_ip,
                    'in_ban_list' => isset($ban_list[$client_ip]),
                    'ban_info' => $ban_list[$client_ip] ?? null,
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // 4. SQL injection check with detailed pattern matching
        if (!$spam && ($features['sql_injection'] ?? true)) {
            foreach ($posted_data as $key => $value) {
                if (is_string($value)) {
                    $detection_result = $this->detect_sql_injection_with_details($value);
                    if ($detection_result['detected']) {
                        $spam = true;
                        $reason = 'SQL injection attempt in field: ' . $key . ' - pattern: ' . $detection_result['pattern'];
                        $reasons[] = $reason;
                        $detailed_reasons[] = [
                            'type' => 'SQL_INJECTION',
                            'message' => $reason,
                            'field' => $key,
                            'value_sample' => substr($value, 0, 100) . (strlen($value) > 100 ? '...' : ''),
                            'matched_pattern' => $detection_result['pattern'],
                            'pattern_index' => $detection_result['pattern_index']
                        ];
                        $attack_types[] = 'SQL_INJECTION';
                        break;
                    }
                }
            }

            if ($debug_data) {
                $debug_data['checks']['sql_injection'] = [
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // 5. XSS check with detailed pattern matching
        if (!$spam && ($features['xss_protection'] ?? true)) {
            foreach ($posted_data as $key => $value) {
                if (is_string($value)) {
                    $detection_result = $this->detect_xss_with_details($value);
                    if ($detection_result['detected']) {
                        $spam = true;
                        $reason = 'XSS attempt in field: ' . $key . ' - pattern: ' . $detection_result['pattern'];
                        $reasons[] = $reason;
                        $detailed_reasons[] = [
                            'type' => 'XSS_ATTACK',
                            'message' => $reason,
                            'field' => $key,
                            'value_sample' => substr($value, 0, 100) . (strlen($value) > 100 ? '...' : ''),
                            'matched_pattern' => $detection_result['pattern'],
                            'pattern_index' => $detection_result['pattern_index']
                        ];
                        $attack_types[] = 'XSS_ATTACK';
                        break;
                    }
                }
            }

            if ($debug_data) {
                $debug_data['checks']['xss_check'] = [
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // 6. Bot detection with detailed analysis
        if (!$spam && ($features['bot_detection'] ?? true)) {
            $bot_detection = $this->detect_bot_with_details();
            if ($bot_detection['is_bot']) {
                $spam = true;
                $reason = 'Bot detected: ' . $bot_detection['reason'];
                $reasons[] = $reason;
                $detailed_reasons[] = [
                    'type' => 'BOT_DETECTED',
                    'message' => $reason,
                    'user_agent' => $bot_detection['user_agent'],
                    'matched_keyword' => $bot_detection['matched_keyword'],
                    'analysis' => $bot_detection['analysis']
                ];
                $attack_types[] = 'BOT_DETECTED';
            }

            if ($debug_data) {
                $debug_data['checks']['bot_detection'] = array_merge(
                    ['result' => $spam ? 'FAIL' : 'PASS'],
                    $bot_detection,
                    ['detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null]
                );
            }
        }

        // 7. Rate limiting
        if (!$spam && ($features['rate_limiting'] ?? true)) {
            $rate_limit_key = 'cf7sec_rate_' . $this->get_client_ip();
            $requests = get_transient($rate_limit_key) ?: 0;
            $max_requests = $options['max_requests'] ?? self::MAX_REQUESTS_PER_MINUTE;

            if ($requests >= $max_requests) {
                $spam = true;
                $reason = 'Rate limit exceeded: ' . $requests . ' requests in last minute (limit: ' . $max_requests . ')';
                $reasons[] = $reason;
                $detailed_reasons[] = [
                    'type' => 'RATE_LIMIT',
                    'message' => $reason,
                    'requests' => $requests,
                    'max_requests' => $max_requests,
                    'time_window' => '60 seconds'
                ];
                $attack_types[] = 'RATE_LIMIT_EXCEEDED';

                // Auto-ban if threshold reached
                $attack_count = get_transient('cf7sec_attack_' . $this->get_client_ip()) ?: 0;
                $attack_count++;
                set_transient('cf7sec_attack_' . $this->get_client_ip(), $attack_count, 3600);

                $ban_threshold = $options['ban_threshold'] ?? self::BAN_THRESHOLD;
                if ($attack_count >= $ban_threshold) {
                    $this->ban_ip($this->get_client_ip(), 'Rate limit threshold exceeded (' . $attack_count . ' attacks)', 'RATE_LIMIT_EXCEEDED', 0);
                    $detailed_reasons[count($detailed_reasons) - 1]['auto_banned'] = true;
                    $detailed_reasons[count($detailed_reasons) - 1]['ban_threshold'] = $ban_threshold;
                }
            } else {
                set_transient($rate_limit_key, $requests + 1, 60);
            }

            if ($debug_data) {
                $debug_data['checks']['rate_limiting'] = [
                    'requests' => $requests,
                    'max_requests' => $max_requests,
                    'threshold_exceeded' => $requests >= $max_requests,
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // 8. Content spam check
        if (!$spam) {
            foreach ($posted_data as $key => $value) {
                if (is_string($value)) {
                    // Check for excessive links
                    $link_count = preg_match_all('#https?://#', $value, $matches);
                    if ($link_count > 2) {
                        $spam = true;
                        $reason = 'Excessive links (' . $link_count . ') in field: ' . $key;
                        $reasons[] = $reason;
                        $detailed_reasons[] = [
                            'type' => 'LINK_SPAM',
                            'message' => $reason,
                            'field' => $key,
                            'link_count' => $link_count,
                            'threshold' => 2
                        ];
                        $attack_types[] = 'LINK_SPAM';
                        break;
                    }

                    // Check for spam keywords
                    $spam_keywords = ['viagra', 'casino', 'lottery', 'loan', 'росписи', 'срочно', 'быстро', 'buy now', 'click here'];
                    foreach ($spam_keywords as $keyword) {
                        if (stripos($value, $keyword) !== false) {
                            $spam = true;
                            $reason = 'Spam keyword "' . $keyword . '" found in field: ' . $key;
                            $reasons[] = $reason;
                            $detailed_reasons[] = [
                                'type' => 'KEYWORD_SPAM',
                                'message' => $reason,
                                'field' => $key,
                                'keyword' => $keyword,
                                'value_sample' => substr($value, 0, 100) . (strlen($value) > 100 ? '...' : '')
                            ];
                            $attack_types[] = 'KEYWORD_SPAM';
                            break 2;
                        }
                    }
                }
            }

            if ($debug_data) {
                $debug_data['checks']['content_check'] = [
                    'result' => $spam ? 'FAIL' : 'PASS',
                    'detailed_reason' => $detailed_reasons[count($detailed_reasons) - 1] ?? null
                ];
            }
        }

        // Log the result if debug mode is enabled
        if ($spam && $this->debug_mode && $debug_data) {
            $debug_data['final_result'] = $spam ? 'SPAM' : 'CLEAN';
            $debug_data['reasons'] = $reasons;
            $debug_data['detailed_reasons'] = $detailed_reasons;
            $debug_data['attack_types'] = $attack_types;
            $debug_data['error_messages_to_display'] = $this->get_error_messages_for_display($detailed_reasons);
            $this->write_debug_log($debug_data, $form_id);

            // Store debug data for output using transients instead of non-existent set_property()
            // FIXED: Replaced $submission->set_property() with transient storage
            $transient_key = 'cf7sec_debug_' . $form_id . '_' . $this->get_client_ip();
            set_transient($transient_key, $debug_data, 60); // Store for 60 seconds
        }


        // Log to history
        $this->log_form_check([
            'form_id' => $form_id,
            'ip' => $this->get_client_ip(),
            'result' => $spam ? 'BLOCKED' : 'CLEAN',
            'reasons' => $reasons,
            'detailed_reasons' => $detailed_reasons,
            'attack_types' => $attack_types
        ]);

        // If spam was detected, increment blocked counter
        if ($spam) {
            $this->increment_counter('blocked_submissions');
            $this->log_security_event('SPAM_DETECTED', [
                'form_id' => $form_id,
                'ip' => $this->get_client_ip(),
                'reasons' => $reasons,
                'detailed_reasons' => $detailed_reasons,
                'attack_types' => $attack_types
            ]);
        }

        return $spam;
    }

    /**
     * Detect SQL injection with detailed information
     */
    private function detect_sql_injection_with_details(string $value): array
    {
        foreach (self::SQL_INJECTION_PATTERNS as $index => $pattern) {
            if (preg_match($pattern, $value, $matches)) {
                return [
                    'detected' => true,
                    'pattern' => $pattern,
                    'pattern_index' => $index,
                    'matched_string' => $matches[0] ?? '',
                    'description' => $this->get_sql_pattern_description($index)
                ];
            }
        }
        return ['detected' => false];
    }

    /**
     * Get description for SQL injection pattern
     */
    private function get_sql_pattern_description(int $index): string
    {
        $descriptions = [
            'SQL command detected (SELECT, INSERT, UPDATE, DELETE, etc.)',
            'Always true condition (OR 1=1, AND 1=1)',
            'SQL comment injection (--, /* */)',
            'Time-based SQL injection (SLEEP, BENCHMARK, WAITFOR)',
            'Database version detection (@@version, version())',
            'File operations (LOAD_FILE, INTO OUTFILE/DUMPFILE)'
        ];
        return $descriptions[$index] ?? 'Unknown SQL injection pattern';
    }

    /**
     * Detect XSS with detailed information
     */
    private function detect_xss_with_details(string $value): array
    {
        foreach (self::XSS_PATTERNS as $index => $pattern) {
            if (preg_match($pattern, $value, $matches)) {
                return [
                    'detected' => true,
                    'pattern' => $pattern,
                    'pattern_index' => $index,
                    'matched_string' => $matches[0] ?? '',
                    'description' => $this->get_xss_pattern_description($index)
                ];
            }
        }
        return ['detected' => false];
    }

    /**
     * Get description for XSS pattern
     */
    private function get_xss_pattern_description(int $index): string
    {
        $descriptions = [
            'Script tag detected (<script>)',
            'JavaScript protocol (javascript:)',
            'Event handler (onclick, onload, etc.)',
            'Data URI scheme (data:)',
            'IFrame tag detected (<iframe>)',
            'Object tag detected (<object>)',
            'Embed tag detected (<embed>)',
            'Applet tag detected (<applet>)',
            'Meta refresh tag',
            'CSS expression'
        ];
        return $descriptions[$index] ?? 'Unknown XSS pattern';
    }

    /**
     * Check if a keyword is part of a browser name to avoid false positives
     * This prevents keywords like "moz" from triggering on "Mozilla"
     */
    private function is_part_of_browser_name(string $keyword, string $user_agent): bool
    {
        $browser_prefixes = [
            'moz' => ['mozilla'],
            'bot' => ['robot', 'botany', 'bottega'],
            'crawl' => ['crawler'],
            'spider' => ['spiderman', 'spider-man'],
            'java' => ['javascript'],
            'python' => ['python-requests'], // Allow python-requests but not generic python
            'curl' => ['curl/'], // Only flag if it's specifically curl/version
        ];

        foreach ($browser_prefixes as $problem_keyword => $browser_terms) {
            if (strpos($keyword, $problem_keyword) === 0 || $keyword === $problem_keyword) {
                foreach ($browser_terms as $browser_term) {
                    // Check if the browser term exists in the user agent
                    if (stripos($user_agent, $browser_term) !== false) {
                        // Check if the keyword is actually part of the browser term
                        $pos = stripos($user_agent, $browser_term);
                        $context = substr($user_agent, max(0, $pos - 10), min(30, strlen($user_agent) - $pos + 10));

                        // If the keyword appears within the browser term context, it's a false positive
                        if (stripos($context, $keyword) !== false) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a bot keyword represents strong bot evidence
     * Some keywords are more definitive than others
     */
    private function is_strong_bot_evidence(string $keyword): bool
    {
        $strong_bots = [
            'googlebot',
            'bingbot',
            'yandexbot',
            'baiduspider',
            'semrushbot',
            'ahrefsbot',
            'mj12bot',
            'dotbot',
            'exabot',
            'rogerbot',
            'gptbot',
            'chatgpt-user',
            'webcrawler',
            'searchbot',
            'indexer',
            'scanner',
            'sqlmap',
            'nikto',
            'httrack',
            'masscan',
            'nmap',
        ];

        return in_array(strtolower($keyword), $strong_bots);
    }

    /**
     * Clean bot keywords list to remove problematic entries
     * Removes generic keywords that cause too many false positives
     */
    private function clean_bot_keywords(array $keywords): array
    {
        $problematic = [
            'bot',        // Too generic, part of many legitimate strings
            'crawl',      // Could be part of "crawler" but also other terms
            'spider',     // Could be part of other words
            'scrape',     // Could be legitimate in some contexts
            'moz',        // Part of "Mozilla" - major false positive source
            'java',       // Too generic, could be Java application
            'python',     // Too generic, could be Python application
            'php',        // Too generic
            'ruby',       // Too generic
            'perl',       // Too generic
            'node',       // Too generic
            'curl',       // Could be legitimate curl library usage
            'wget',       // Could be legitimate wget usage
            'request',    // Too generic
            'libwww',     // Could be legitimate library
            'zgrab',      // Might have legitimate uses
            'webcopy',    // Could be legitimate tool
            'sitebulb',   // Might be legitimate SEO tool
            'screaming frog', // Might be legitimate SEO tool
            'semrush',    // Company name, not necessarily bot
            'ahrefs',     // Company name, not necessarily bot
            'moz',        // Company name (SEO tool)
            'megaindex',  // Might be legitimate tool
            'anthropic',  // Company name
            'claude',     // Could be legitimate
            'duckduckbot', // Search engine bot - might be desirable
            'teoma',      // Search engine
            'slurp',      // Yahoo search - might be desirable
        ];

        // Remove problematic keywords (exact matches or starts with)
        $cleaned = array_filter($keywords, function ($keyword) use ($problematic) {
            $keyword_lower = strtolower($keyword);
            foreach ($problematic as $problem) {
                if (
                    $keyword_lower === $problem ||
                    strpos($keyword_lower, $problem . '-') === 0 ||
                    strpos($keyword_lower, $problem . '_') === 0
                ) {
                    return false;
                }
            }
            return true;
        });

        // Add specific, unambiguous bot identifiers
        $specific_bots = [
            // Search engine bots (configurable via settings)
            'googlebot',
            'googlebot-news',
            'googlebot-image',
            'googlebot-video',
            'bingbot',
            'msnbot',
            'msnbot-media',
            'yandexbot',
            'yandeximages',
            'yandexvideo',
            'baiduspider',
            'baiduspider-image',
            'baiduspider-video',

            // Known scraper/spam bots
            'semrushbot',
            'ahrefsbot',
            'mj12bot',
            'dotbot',
            'exabot',
            'rogerbot',

            // AI/chat bots
            'gptbot',
            'chatgpt-user',
            'anthropic-ai',
            'claude-bot',

            // Security scanners (definitely unwanted)
            'sqlmap',
            'nikto',
            'masscan',
            'nmap',
            'zgrab',

            // Archiving/copying tools (usually unwanted)
            'httrack',
            'webcopy',
            'sitebulb',
            'screamingfrog',

            // Generic bot patterns (with bot suffix)
            'webcrawler',
            'searchbot',
            'indexer',
            'scanner',
            'harvester',
        ];

        // Merge and remove duplicates
        $result = array_merge($cleaned, $specific_bots);
        $result = array_unique($result);

        // Sort alphabetically for consistency
        sort($result);

        return $result;
    }

    /**
     * Detect bot with detailed analysis and improved logic to prevent false positives
     * FIXED: Key improvements:
     * 1. Prevent false positives for browser keywords like "moz" in "Mozilla"
     * 2. Use word boundaries to avoid partial matches
     * 3. Improved decision logic with better thresholds
     * 4. Special handling for known browser prefixes
     */
    private function detect_bot_with_details(): array
    {
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $analysis = [
            'user_agent' => $user_agent,
            'length' => strlen($user_agent),
            'common_browser_indicators' => 0,
            'bot_indicators' => 0,
            'detailed_matches' => []
        ];

        // Check for common browser indicators (case-insensitive)
        $browser_indicators = ['mozilla', 'chrome', 'safari', 'firefox', 'edge', 'opera', 'webkit', 'gecko', 'msie', 'trident'];
        foreach ($browser_indicators as $indicator) {
            if (stripos($user_agent, $indicator) !== false) {
                $analysis['common_browser_indicators']++;
                $position = stripos($user_agent, $indicator);
                $analysis['detailed_matches'][] = [
                    'type' => 'browser_indicator',
                    'value' => $indicator,
                    'position' => $position,
                    'context' => substr($user_agent, max(0, $position - 20), min(40, strlen($user_agent) - $position + 20))
                ];
            }
        }

        // Get cleaned bot keywords (without problematic ones that cause false positives)
        $cleaned_bot_keywords = $this->clean_bot_keywords(self::BOT_USER_AGENTS);

        // Check for bot indicators with improved logic to avoid false positives
        foreach ($cleaned_bot_keywords as $bot_keyword) {
            $keyword_lower = strtolower($bot_keyword);

            // Skip if keyword is part of a common browser name (e.g., "moz" in "mozilla")
            if ($this->is_part_of_browser_name($keyword_lower, $user_agent)) {
                continue;
            }

            // Search for bot keyword as whole word with word boundaries
            // This prevents partial matches like "moz" matching "mozilla"
            $pattern = '/\b' . preg_quote($keyword_lower, '/') . '\b/i';
            if (preg_match($pattern, $user_agent)) {
                $analysis['bot_indicators']++;
                $position = stripos($user_agent, $keyword_lower);
                $analysis['detailed_matches'][] = [
                    'type' => 'bot_indicator',
                    'value' => $bot_keyword,
                    'position' => $position,
                    'context' => substr($user_agent, max(0, $position - 20), min(40, strlen($user_agent) - $position + 20))
                ];

                // Only mark as bot if there's strong evidence
                // If browser indicators are present, require additional verification
                if ($analysis['common_browser_indicators'] === 0 || $this->is_strong_bot_evidence($keyword_lower)) {
                    return [
                        'is_bot' => true,
                        'reason' => "User agent contains bot keyword: '{$bot_keyword}'",
                        'matched_keyword' => $bot_keyword,
                        'user_agent' => $user_agent,
                        'analysis' => $analysis
                    ];
                }
            }
        }

        // Additional bot detection logic with thresholds
        $suspicious_patterns = [
            '/^[a-z0-9\s\.]+$/i' => 'No browser identification',
            '/\d+\.\d+\.\d+\.\d+/' => 'Contains IP address',
            '/\(compatible[^)]+\)/i' => 'Generic "compatible" tag',
            '/^[^\(\)\s]+\/[0-9\.]+$/i' => 'Simple user agent without details'
        ];

        foreach ($suspicious_patterns as $pattern => $description) {
            if (preg_match($pattern, $user_agent)) {
                $analysis['bot_indicators']++;
                $analysis['detailed_matches'][] = [
                    'type' => 'suspicious_pattern',
                    'pattern' => $pattern,
                    'description' => $description
                ];
            }
        }

        // Improved decision logic with better thresholds
        $is_bot = false;
        $reason = '';

        // Decision matrix based on indicators
        if ($analysis['common_browser_indicators'] > 0) {
            // Browser detected - be more conservative
            if ($analysis['bot_indicators'] >= 3) {
                $is_bot = true;
                $reason = 'Multiple (' . $analysis['bot_indicators'] . ') suspicious patterns detected despite browser indicators';
            } elseif (strlen($user_agent) < 30) {
                // Very short UA even with browser indicators is suspicious
                $is_bot = true;
                $reason = 'User agent too short (' . strlen($user_agent) . ' chars) for a real browser';
            }
        } else {
            // No browser indicators - more likely to be a bot
            if ($analysis['bot_indicators'] > 0) {
                $is_bot = true;
                $reason = 'No browser indicators found, but ' . $analysis['bot_indicators'] . ' suspicious patterns detected';
            } elseif (strlen($user_agent) < 20) {
                $is_bot = true;
                $reason = 'User agent too short (' . strlen($user_agent) . ' chars) - typical browsers have longer UAs';
            } elseif (preg_match('/^[a-z]+\/[0-9\.]+(\s+[a-z]+\/[0-9\.]+)*$/i', $user_agent)) {
                // Pattern like "library/version library/version" without browser info
                $is_bot = true;
                $reason = 'User agent appears to be a library/script pattern without browser identification';
            }
        }

        // Special case: If we have browser indicators but bot indicators, it's probably not a bot
        // unless the bot evidence is very strong
        if ($analysis['common_browser_indicators'] >= 2 && $analysis['bot_indicators'] <= 1) {
            $is_bot = false;
            $reason = 'Strong browser indicators outweigh minor bot indicators';
        }

        return [
            'is_bot' => $is_bot,
            'reason' => $is_bot ? $reason : 'No bot patterns matched or insufficient evidence',
            'matched_keyword' => null,
            'user_agent' => $user_agent,
            'analysis' => $analysis
        ];
    }


    /**
     * Get error messages for display based on detailed reasons
     */
    private function get_error_messages_for_display(array $detailed_reasons): array
    {
        $messages = [];

        foreach ($detailed_reasons as $reason) {
            $type = $reason['type'] ?? '';
            $field = $reason['field'] ?? '';
            $language_name = '';

            // Get appropriate error message
            $message_key = strtolower($type);
            $message_key = str_replace('_attack', '', $message_key);
            $message_key = str_replace('_check', '', $message_key);
            $message_key = str_replace('_detected', '', $message_key);
            $message_key = str_replace('_exceeded', '', $message_key);

            $message_template = $this->error_messages[$message_key] ?? $this->error_messages['bot_detected'];

            // Replace placeholders
            $message = str_replace('%field_name%', $field, $message_template);
            $message = str_replace('%language_name%', $language_name, $message);
            $message = str_replace('%minutes%', '1', $message); // Default 1 minute for rate limit
            $message = str_replace('%ip_address%', $this->get_client_ip(), $message);
            $message = str_replace('%bot_keyword%', $reason['matched_keyword'] ?? '', $message);

            $messages[] = [
                'type' => $type,
                'field' => $field,
                'message' => $message,
                'detailed_reason' => $reason
            ];
        }

        return $messages;
    }

    /**
     * Sanitize posted data for logging (remove sensitive information)
     */
    private function sanitize_posted_data_for_log(array $posted_data): array
    {
        $sanitized = [];
        $sensitive_fields = ['password', 'creditcard', 'cc', 'cvv', 'ssn', 'passport'];

        foreach ($posted_data as $key => $value) {
            $key_lower = strtolower($key);
            $is_sensitive = false;

            foreach ($sensitive_fields as $sensitive) {
                if (strpos($key_lower, $sensitive) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ($is_sensitive && !empty($value)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = is_string($value) ? substr($value, 0, 200) : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Add debug output to CF7 AJAX response
     * FIXED: Properly escape HTML and only show when form submission failed
     */
    public function add_debug_output_to_response(array $response, array $result): array
    {
        $options = get_option($this->options_name, []);

        // Only add debug output if debug mode and debug output are enabled
        // AND only if the form submission had errors or was marked as spam
        if ($this->debug_mode && ($options['debug_output'] ?? false)) {
            // Only show debug info if there was an error or spam detection
            if (($result['status'] ?? '') === 'mail_failed' ||
                ($result['status'] ?? '') === 'validation_failed' ||
                ($response['cf7sec_debug'] ?? false)
            ) {

                // Get debug data from transient storage
                $form_id = $result['contact_form_id'] ?? 0;
                $debug_data = $this->get_stored_debug_data($form_id);

                if ($debug_data) {
                    // Create debug HTML output with proper escaping
                    $debug_html = '<div class="cf7sec-debug-output" style="margin-top: 20px; padding: 15px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; font-family: monospace; font-size: 12px;">';
                    $debug_html .= '<h4 style="margin-top: 0; color: #333;">CF7 Security Pro Debug Info</h4>';

                    // Summary
                    $result_bg = ($debug_data['final_result'] ?? '') === 'SPAM' ? '#f8d7da' : '#d4edda';
                    $debug_html .= '<div style="margin-bottom: 15px; padding: 10px; background: ' . esc_attr($result_bg) . '; border-radius: 3px;">';
                    $debug_html .= '<strong>Result:</strong> ' . esc_html($debug_data['final_result'] ?? 'UNKNOWN') . '<br>';
                    $debug_html .= '<strong>Form ID:</strong> ' . esc_html($debug_data['form_id'] ?? 0) . '<br>';
                    $debug_html .= '<strong>Client IP:</strong> ' . esc_html($debug_data['client_ip'] ?? '') . '<br>';
                    $debug_html .= '<strong>Timestamp:</strong> ' . esc_html(date('Y-m-d H:i:s', strtotime($debug_data['timestamp'] ?? 'now'))) . '<br>';
                    $debug_html .= '</div>';

                    // Detailed reasons if any
                    if (!empty($debug_data['detailed_reasons'])) {
                        $debug_html .= '<div style="margin-bottom: 15px;">';
                        $debug_html .= '<strong>Detection Details:</strong><br>';
                        foreach ($debug_data['detailed_reasons'] as $reason) {
                            $debug_html .= '<div style="padding: 5px; margin: 2px 0; background: #fff3cd; border-radius: 3px;">';
                            $debug_html .= '<strong>' . esc_html($reason['type'] ?? 'Unknown') . ':</strong> ' . esc_html($reason['message'] ?? '') . '<br>';

                            // Add specific details based on type
                            if (isset($reason['matched_keyword'])) {
                                $debug_html .= 'Keyword: ' . esc_html($reason['matched_keyword']) . '<br>';
                            }

                            $debug_html .= '</div>';
                        }
                        $debug_html .= '</div>';
                    }

                    // Security features status
                    if (!empty($debug_data['security_features_enabled'])) {
                        $debug_html .= '<div style="margin-bottom: 15px;">';
                        $debug_html .= '<strong>Security Features Status:</strong><br>';
                        foreach ($debug_data['security_features_enabled'] as $feature => $enabled) {
                            $color = $enabled ? '#155724' : '#721c24';
                            $status = $enabled ? 'Enabled' : 'Disabled';
                            $debug_html .= esc_html($feature) . ': <span style="color: ' . esc_attr($color) . '">' . esc_html($status) . '</span><br>';
                        }
                        $debug_html .= '</div>';
                    }

                    // Checks performed
                    if (!empty($debug_data['checks'])) {
                        $debug_html .= '<div style="margin-bottom: 15px;">';
                        $debug_html .= '<strong>Checks Performed:</strong><br>';
                        foreach ($debug_data['checks'] as $check => $data) {
                            $color = ($data['result'] ?? '') === 'FAIL' ? '#721c24' : '#155724';
                            $debug_html .= esc_html($check) . ': <span style="color: ' . esc_attr($color) . '">' . esc_html($data['result'] ?? 'Unknown') . '</span><br>';
                        }
                        $debug_html .= '</div>';
                    }

                    // User agent analysis for bot detection
                    if (isset($debug_data['checks']['bot_detection'])) {
                        $bot_check = $debug_data['checks']['bot_detection'];
                        if (isset($bot_check['analysis'])) {
                            $debug_html .= '<div style="margin-bottom: 15px;">';
                            $debug_html .= '<strong>User Agent Analysis:</strong><br>';
                            $debug_html .= 'User Agent: ' . esc_html(substr($bot_check['user_agent'] ?? '', 0, 100)) . '<br>';
                            $debug_html .= 'Length: ' . esc_html($bot_check['analysis']['length'] ?? 0) . ' chars<br>';
                            $debug_html .= 'Browser Indicators: ' . esc_html($bot_check['analysis']['common_browser_indicators'] ?? 0) . '<br>';
                            $debug_html .= 'Bot Indicators: ' . esc_html($bot_check['analysis']['bot_indicators'] ?? 0) . '<br>';

                            if (!empty($bot_check['analysis']['detailed_matches'])) {
                                $debug_html .= '<div style="padding: 5px; background: #e9ecef; border-radius: 3px; margin-top: 5px;">';
                                $debug_html .= '<strong>Detailed Matches:</strong><br>';
                                foreach ($bot_check['analysis']['detailed_matches'] as $match) {
                                    $debug_html .= '• ' . esc_html($match['type'] ?? '') . ': ' . esc_html($match['value'] ?? '') . '<br>';
                                }
                                $debug_html .= '</div>';
                            }

                            $debug_html .= '</div>';
                        }
                    }

                    $debug_html .= '</div>';

                    // Add debug info to response message
                    // FIXED: Only append if there's a message to avoid raw HTML
                    if (!empty($result['message'])) {
                        $response['message'] = $result['message'] . $debug_html;
                    } else {
                        // If no message, add to a separate field
                        $response['cf7sec_debug_html'] = $debug_html;
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Get stored debug data for a specific form
     * NEW: Storage mechanism for debug data between check_spam() and add_debug_output_to_response()
     */
    private function get_stored_debug_data(int $form_id): ?array
    {
        // Use transients to store debug data temporarily (expires in 60 seconds)
        $transient_key = 'cf7sec_debug_' . $form_id . '_' . $this->get_client_ip();
        $debug_data = get_transient($transient_key);

        // Clean up after retrieval
        if ($debug_data) {
            delete_transient($transient_key);
        }

        return $debug_data ?: null;
    }

    /**
     * Validate field for language with proper error messages
     */
    public function validate_field(WPCF7_Validation $result, WPCF7_FormTag $tag): WPCF7_Validation
    {
        $options = get_option($this->options_name, []);
        $lang_settings = $options['language_settings'] ?? [];

        // Skip if language validation is disabled
        if (empty($lang_settings['enabled'])) {
            return $result;
        }

        $field_name = $tag->name;
        $custom_fields = $lang_settings['custom_fields'] ?? implode(',', self::DEFAULT_NAME_FIELDS);
        $field_list = array_map('trim', explode(',', $custom_fields));

        // Check if this field should be validated
        if (in_array($field_name, $field_list)) {
            $selected_language = $lang_settings['selected_language'] ?? 'russian';
            $value = isset($_POST[$field_name]) ? trim($_POST[$field_name]) : '';

            if (!empty($value) && isset($this->languages[$selected_language])) {
                $regex = $this->languages[$selected_language]['regex'];

                // Check if value matches the language pattern
                if (!preg_match($regex, $value)) {
                    // Get custom error message
                    $error_message = $lang_settings['validation_error_message'] ?? $this->error_messages['language_validation'];
                    $error_message = str_replace('%field_name%', $field_name, $error_message);
                    $error_message = str_replace('%language_name%', $this->languages[$selected_language]['name'], $error_message);

                    // Add validation error - THIS SHOWS ERROR TO USER WITHOUT BANNING
                    $result->invalidate($tag, $error_message);

                    // Log validation failure in debug mode (but don't ban IP)
                    if ($this->debug_mode) {
                        $this->write_debug_log([
                            'event' => 'LANGUAGE_VALIDATION_FAILED',
                            'field' => $field_name,
                            'value' => $value,
                            'expected_language' => $selected_language,
                            'language_name' => $this->languages[$selected_language]['name'],
                            'regex_used' => $regex,
                            'action' => 'Validation error shown to user (no IP ban)',
                            'user_message' => $error_message
                        ], 0);
                    }
                } else {
                    // Log successful validation in debug mode
                    if ($this->debug_mode) {
                        $this->write_debug_log([
                            'event' => 'LANGUAGE_VALIDATION_PASSED',
                            'field' => $field_name,
                            'value' => substr($value, 0, 50) . (strlen($value) > 50 ? '...' : ''),
                            'expected_language' => $selected_language,
                            'language_name' => $this->languages[$selected_language]['name'],
                            'action' => 'Field passed language validation'
                        ], 0);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Write debug log to file with enhanced formatting and error handling
     * IMPROVED: Added error handling and permissions check
     */
    private function write_debug_log(array $data, int $form_id): void
    {
        // Ensure log directory exists and is writable
        $this->create_log_dir();

        $timestamp = date('Ymd_His');
        $filename = "debug_{$timestamp}_form_{$form_id}.json";
        $filepath = CF7SEC_LOG_DIR . $filename;

        $log_entry = [
            'timestamp' => date('c'),
            'form_id' => $form_id,
            'client_ip' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'data' => $data
        ];

        // Try to write with error handling
        $json_content = json_encode($log_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json_content === false) {
            error_log('CF7 Security Pro: Failed to encode debug log to JSON');
            return;
        }

        $result = @file_put_contents($filepath, $json_content);

        if ($result === false) {
            error_log('CF7 Security Pro: Failed to write debug log to ' . $filepath);
            // Try alternative location
            $alt_path = WP_CONTENT_DIR . '/debug_cf7sec_' . $filename;
            @file_put_contents($alt_path, $json_content);
        }
    }

    /**
     * Debug Mode Page with enhanced options
     * FIXED: Added proper checkbox values for unchecked state
     */
    public function debug_mode_page(): void
    {
        $options = get_option($this->options_name, []);
        $debug_mode = $this->debug_mode;
        $debug_output = $options['debug_output'] ?? false;
        $recent_logs = $this->get_recent_logs(10);
    ?>
        <div class="wrap">
            <h1>Debug Mode Settings</h1>
            <p class="description">Enable detailed logging for troubleshooting and monitoring form submissions.</p>

            <form method="post" action="options.php" class="cf7sec-settings-form">
                <?php settings_fields('cf7sec_settings'); ?>

                <div class="postbox">
                    <div class="inside">
                        <h2>Debug Mode Configuration</h2>

                        <table class="form-table">
                            <tr>
                                <th><label for="debug_mode">Enable Debug Mode</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="debug_mode"
                                            name="cf7sec_options[debug_mode]"
                                            value="1" <?php checked($debug_mode, true, true); ?>>
                                        Enable detailed logging of all form submissions
                                    </label>
                                    <p class="description">
                                        When enabled, detailed logs will be created for each form submission, including all validation checks,
                                        results, and reasons for blocking. This is useful for troubleshooting but may impact performance.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="debug_output">Show Debug Info to Users</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="debug_output"
                                            name="cf7sec_options[debug_output]"
                                            value="1" <?php checked($debug_output, true, true); ?>>
                                        Show debug information under form after submission
                                    </label>
                                    <p class="description">
                                        When enabled, detailed debug information will be shown to users after form submission.
                                        <strong>Warning:</strong> This may expose technical details to end users. Use only for troubleshooting.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('Save Debug Settings', 'primary', 'submit', true); ?>
                    </div>
                </div>
            </form>

            <?php if ($debug_mode): ?>
                <div class="postbox" style="margin-top: 30px;">
                    <div class="inside">
                        <h2>Debug Information</h2>

                        <div class="debug-info-grid">
                            <div class="debug-info-card">
                                <h4>Log Directory</h4>
                                <code><?php echo esc_html(CF7SEC_LOG_DIR); ?></code>
                                <p class="description">All debug logs are stored in this directory</p>
                            </div>

                            <div class="debug-info-card">
                                <h4>History File</h4>
                                <code><?php echo esc_html(CF7SEC_HISTORY_FILE); ?></code>
                                <p class="description">Form check history storage</p>
                            </div>

                            <div class="debug-info-card">
                                <h4>Ban List</h4>
                                <code><?php echo esc_html(CF7SEC_LOG_DIR . 'ban_list.json'); ?></code>
                                <p class="description">IP ban list storage</p>
                            </div>

                            <div class="debug-info-card">
                                <h4>Security Events</h4>
                                <code><?php echo esc_html(CF7SEC_LOG_DIR . 'security_events.json'); ?></code>
                                <p class="description">Security event log storage</p>
                            </div>
                        </div>

                        <div class="debug-actions">
                            <button type="button" class="button button-primary" id="download-logs">
                                <span class="dashicons dashicons-download"></span> Download Debug Logs
                            </button>
                            <button type="button" class="button" id="clear-logs">
                                <span class="dashicons dashicons-trash"></span> Clear Debug Logs
                            </button>
                            <button type="button" class="button" id="view-log-dir">
                                <span class="dashicons dashicons-admin-generic"></span> View Log Directory
                            </button>
                        </div>
                    </div>
                </div>

                <div class="postbox" style="margin-top: 30px;">
                    <div class="inside">
                        <h2>Recent Debug Logs</h2>

                        <?php if (empty($recent_logs)): ?>
                            <p>No debug logs found.</p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>Filename</th>
                                        <th>Size</th>
                                        <th>Modified</th>
                                        <th>Form ID</th>
                                        <th>Result</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_logs as $log):
                                        // Try to read result from log file
                                        $result = 'Unknown';
                                        if (file_exists($log['path'])) {
                                            $content = file_get_contents($log['path']);
                                            $data = json_decode($content, true);
                                            if ($data && isset($data['data']['final_result'])) {
                                                $result = $data['data']['final_result'];
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td><code><?php echo esc_html($log['filename']); ?></code></td>
                                            <td><?php echo esc_html($log['size']); ?></td>
                                            <td><?php echo esc_html($log['modified']); ?></td>
                                            <td><?php echo esc_html($log['form_id']); ?></td>
                                            <td>
                                                <span class="result-badge result-<?php echo strtolower($result); ?>">
                                                    <?php echo esc_html($result); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small view-log"
                                                    data-filename="<?php echo esc_attr($log['filename']); ?>">
                                                    View
                                                </button>
                                                <button type="button" class="button button-small delete-log"
                                                    data-filename="<?php echo esc_attr($log['filename']); ?>">
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="postbox" style="margin-top: 30px;">
                <div class="inside">
                    <h2>Log Viewer</h2>
                    <div id="log-viewer-container" style="display: none;">
                        <pre id="log-content" style="background: #f5f5f5; padding: 15px; border-radius: 5px; max-height: 500px; overflow: auto; font-family: monospace; font-size: 12px;"></pre>
                        <div class="log-actions">
                            <button type="button" class="button" id="close-log-viewer">Close</button>
                            <button type="button" class="button" id="copy-log-content">Copy to Clipboard</button>
                            <button type="button" class="button" id="expand-log-content">Expand All</button>
                        </div>
                    </div>
                    <div id="log-viewer-placeholder">
                        <p>Select a log file to view its contents. Log files contain detailed information about form submissions,
                            including all validation checks performed and their results.</p>

                        <div class="example-log" style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-top: 15px; font-family: monospace; font-size: 11px;">
                            <strong>Example debug log structure:</strong><br>
                            {<br>
                            &nbsp;&nbsp;"timestamp": "2026-01-29T20:40:22+00:00",<br>
                            &nbsp;&nbsp;"form_id": 1256,<br>
                            &nbsp;&nbsp;"client_ip": "77.37.192.246",<br>
                            &nbsp;&nbsp;"user_agent": "Mozilla/5.0...",<br>
                            &nbsp;&nbsp;"data": {<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;"final_result": "SPAM",<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;"detailed_reasons": [<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"type": "BOT_DETECTED",<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"message": "User agent contains bot keyword: 'bot'",<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"user_agent": "...",<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"matched_keyword": "bot",<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"analysis": { ... }<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;}<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;],<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;"checks": { ... }<br>
                            &nbsp;&nbsp;}<br>
                            }
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .debug-info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .debug-info-card {
                padding: 15px;
                background: #f8f9fa;
                border-radius: 5px;
                border-left: 3px solid #2271b1;
            }

            .debug-info-card h4 {
                margin-top: 0;
                color: #333;
            }

            .debug-actions {
                display: flex;
                gap: 10px;
                margin-top: 20px;
            }

            .log-actions {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }

            .example-log {
                line-height: 1.4;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // View log file
                $('.view-log').on('click', function() {
                    var filename = $(this).data('filename');
                    var $container = $('#log-viewer-container');
                    var $placeholder = $('#log-viewer-placeholder');
                    var $content = $('#log-content');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'cf7sec_get_log_content',
                            filename: filename,
                            nonce: '<?php echo wp_create_nonce("cf7sec_get_log_content"); ?>'
                        },
                        beforeSend: function() {
                            $content.text('Loading...');
                        },
                        success: function(response) {
                            if (response.success) {
                                try {
                                    var data = JSON.parse(response.data.content);
                                    $content.text(JSON.stringify(data, null, 2));
                                } catch (e) {
                                    $content.text(response.data.content);
                                }
                                $placeholder.hide();
                                $container.show();
                            }
                        }
                    });
                });

                // Delete log file
                $('.delete-log').on('click', function() {
                    var filename = $(this).data('filename');
                    var $row = $(this).closest('tr');

                    if (confirm('Delete log file: ' + filename + '?')) {
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'cf7sec_delete_log',
                                filename: filename,
                                nonce: '<?php echo wp_create_nonce("cf7sec_delete_log"); ?>'
                            },
                            success: function() {
                                $row.fadeOut(function() {
                                    $(this).remove();
                                });
                            }
                        });
                    }
                });

                // Close log viewer
                $('#close-log-viewer').on('click', function() {
                    $('#log-viewer-container').hide();
                    $('#log-viewer-placeholder').show();
                });

                // Copy log content
                $('#copy-log-content').on('click', function() {
                    var logContent = $('#log-content').text();
                    var $temp = $('<textarea>');
                    $('body').append($temp);
                    $temp.val(logContent).select();
                    document.execCommand('copy');
                    $temp.remove();

                    alert('Log content copied to clipboard!');
                });

                // Expand/collapse log content
                $('#expand-log-content').on('click', function() {
                    var $content = $('#log-content');
                    var currentHeight = $content.css('max-height');
                    if (currentHeight === '500px') {
                        $content.css('max-height', 'none');
                        $(this).text('Collapse');
                    } else {
                        $content.css('max-height', '500px');
                        $(this).text('Expand All');
                    }
                });

                // Download logs
                $('#download-logs').on('click', function() {
                    alert('Log download functionality would be implemented here. In production, this would generate a ZIP file of all debug logs.');
                });

                // Clear logs
                $('#clear-logs').on('click', function() {
                    if (confirm('Are you sure you want to clear all debug logs?')) {
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'cf7sec_clear_debug_logs',
                                nonce: '<?php echo wp_create_nonce("cf7sec_clear_debug_logs"); ?>'
                            },
                            beforeSend: function() {
                                $('#clear-logs').prop('disabled', true)
                                    .html('<span class="dashicons dashicons-update"></span> Clearing...');
                            },
                            success: function() {
                                location.reload();
                            }
                        });
                    }
                });

                // View log directory
                $('#view-log-dir').on('click', function() {
                    alert('Log directory: <?php echo esc_js(CF7SEC_LOG_DIR); ?>');
                });
            });
        </script>
    <?php
    }

    /**
     * Language Settings Page with enhanced error message configuration
     */
    public function language_settings_page(): void
    {
        $options = get_option($this->options_name, []);
        $lang_settings = $options['language_settings'] ?? [];

        // Get all CF7 forms with their fields
        $cf7_fields = $this->get_cf7_field_names();
    ?>
        <div class="wrap">
            <h1>Language Validation Settings</h1>
            <p class="description">Configure language validation for name fields to prevent mixed-language spam submissions.</p>

            <form method="post" action="options.php" class="cf7sec-settings-form">
                <?php settings_fields('cf7sec_settings'); ?>

                <div class="postbox">
                    <div class="inside">
                        <h2>Language Validation</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="language_enabled">Enable Language Validation</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="language_enabled"
                                            name="cf7sec_options[language_settings][enabled]"
                                            value="1" <?php checked($lang_settings['enabled'] ?? true); ?>>
                                        Enable language validation for name fields
                                    </label>
                                    <p class="description">
                                        When enabled, name fields will be validated against the selected language's character set.
                                        This prevents submissions with mixed or incorrect language characters.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="selected_language">Select Language</label></th>
                                <td>
                                    <select id="selected_language" name="cf7sec_options[language_settings][selected_language]"
                                        class="regular-text" style="width: 300px;">
                                        <?php foreach ($this->languages as $code => $language): ?>
                                            <option value="<?php echo esc_attr($code); ?>"
                                                <?php selected($lang_settings['selected_language'] ?? 'russian', $code); ?>>
                                                <?php echo esc_html($language['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Select the language that should be used for validating name fields. The plugin supports
                                        22+ languages with proper Unicode character validation.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="strict_mode">Strict Mode</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="strict_mode"
                                            name="cf7sec_options[language_settings][strict_mode]"
                                            value="1" <?php checked($lang_settings['strict_mode'] ?? false); ?>>
                                        Enable strict mode validation
                                    </label>
                                    <p class="description">
                                        In strict mode, fields must contain ONLY characters from the selected language.
                                        In non-strict mode, fields can contain mixed languages but must include at least
                                        some characters from the selected language (useful for international names).
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="custom_fields">Custom Field Names</label></th>
                                <td>
                                    <textarea id="custom_fields"
                                        name="cf7sec_options[language_settings][custom_fields]"
                                        class="large-text" rows="3" style="width: 100%; max-width: 600px;"><?php
                                                                                                            echo esc_textarea($lang_settings['custom_fields'] ?? implode(',', self::DEFAULT_NAME_FIELDS));
                                                                                                            ?></textarea>
                                    <p class="description">
                                        Comma-separated list of field names that should be validated for language.
                                        You can use field names from your Contact Form 7 forms listed below.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="validation_error_message">Custom Error Message</label></th>
                                <td>
                                    <textarea id="validation_error_message"
                                        name="cf7sec_options[language_settings][validation_error_message]"
                                        class="large-text" rows="3" style="width: 100%; max-width: 600px;"><?php
                                                                                                            echo esc_textarea($lang_settings['validation_error_message'] ?? self::DEFAULT_ERROR_MESSAGES['language_validation']);
                                                                                                            ?></textarea>
                                    <p class="description">
                                        Custom error message shown when language validation fails. Use placeholders:
                                        %field_name% (field name), %language_name% (selected language name).
                                        Default: <?php echo esc_html(self::DEFAULT_ERROR_MESSAGES['language_validation']); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="postbox">
                    <div class="inside">
                        <h2>Available Contact Form 7 Fields</h2>
                        <p class="description">Detected forms and their field names. Use these field names in the custom field list above.</p>

                        <?php if (!empty($cf7_fields)): ?>
                            <div class="cf7-fields-container">
                                <?php foreach ($cf7_fields as $form_id => $fields):
                                    $form_title = get_the_title($form_id);
                                ?>
                                    <div class="cf7-form-section">
                                        <h3>
                                            <span class="dashicons dashicons-email-alt"></span>
                                            Form: <?php echo esc_html($form_title ? $form_title : "ID: $form_id"); ?>
                                            <small>(ID: <?php echo esc_html($form_id); ?>)</small>
                                        </h3>
                                        <div class="fields-list">
                                            <?php foreach ($fields as $field): ?>
                                                <span class="field-tag" data-field="<?php echo esc_attr($field); ?>">
                                                    <code><?php echo esc_html($field); ?></code>
                                                    <button type="button" class="copy-field" data-field="<?php echo esc_attr($field); ?>">
                                                        <span class="dashicons dashicons-admin-page"></span>
                                                    </button>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="form-shortcode">
                                            Shortcode: <code>[contact-form-7 id="<?php echo esc_attr($form_id); ?>" title="<?php echo esc_attr($form_title); ?>"]</code>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="notice notice-warning">
                                <p>No Contact Form 7 forms found or no fields detected. Please ensure:</p>
                                <ul>
                                    <li>Contact Form 7 plugin is installed and activated</li>
                                    <li>You have at least one form created</li>
                                    <li>Your forms have fields defined</li>
                                </ul>
                                <p>Once forms are detected, they will appear here automatically.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php submit_button('Save Language Settings', 'primary', 'submit', true); ?>
            </form>
        </div>

        <style>
            .cf7-fields-container {
                margin: 20px 0;
            }

            .cf7-form-section {
                margin-bottom: 25px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 5px;
                border: 1px solid #e0e0e0;
            }

            .cf7-form-section h3 {
                margin-top: 0;
                color: #333;
                padding-bottom: 10px;
                border-bottom: 2px solid #2271b1;
            }

            .cf7-form-section h3 .dashicons {
                color: #2271b1;
                margin-right: 10px;
            }

            .cf7-form-section h3 small {
                font-weight: normal;
                color: #666;
            }

            .fields-list {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin: 15px 0;
            }

            .field-tag {
                display: inline-flex;
                align-items: center;
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 5px 10px;
            }

            .field-tag code {
                font-size: 12px;
                margin-right: 5px;
            }

            .copy-field {
                background: none;
                border: none;
                cursor: pointer;
                padding: 2px;
                margin-left: 5px;
                color: #666;
            }

            .copy-field:hover {
                color: #2271b1;
            }

            .copy-field .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }

            .form-shortcode {
                margin: 10px 0 0;
                padding: 10px;
                background: #e9ecef;
                border-radius: 3px;
                font-size: 12px;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Copy field name to clipboard
                $('.copy-field').on('click', function() {
                    var fieldName = $(this).data('field');
                    var $temp = $('<textarea>');
                    $('body').append($temp);
                    $temp.val(fieldName).select();
                    document.execCommand('copy');
                    $temp.remove();

                    // Show success feedback
                    var $button = $(this);
                    var originalHtml = $button.html();
                    $button.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span>');

                    setTimeout(function() {
                        $button.html(originalHtml);
                    }, 1000);
                });

                // Add field to textarea on click
                $('.field-tag').on('click', function(e) {
                    if (!$(e.target).is('button')) {
                        var fieldName = $(this).data('field');
                        var $textarea = $('#custom_fields');
                        var currentVal = $textarea.val();
                        var fields = currentVal.split(',').map(f => f.trim()).filter(f => f);

                        if (!fields.includes(fieldName)) {
                            fields.push(fieldName);
                            $textarea.val(fields.join(', '));

                            // Highlight the field temporarily
                            $(this).css({
                                'background-color': '#d4edda',
                                'border-color': '#c3e6cb'
                            });

                            setTimeout(() => {
                                $(this).css({
                                    'background-color': '',
                                    'border-color': ''
                                });
                            }, 1000);
                        }
                    }
                });
            });
        </script>
    <?php
    }

    /**
     * Get CF7 form field names with proper detection
     */
    private function get_cf7_field_names(): array
    {
        // Check if Contact Form 7 is active
        if (!class_exists('WPCF7_ContactForm') || !method_exists('WPCF7_ContactForm', 'get_current')) {
            return [];
        }

        $forms = WPCF7_ContactForm::find();
        $fields_by_form = [];

        foreach ($forms as $form) {
            $form_id = $form->id();
            $form_fields = [];

            // Get form properties to access form content
            $form_content = $form->prop('form');

            // Parse form content for field tags - improved regex
            if (preg_match_all('/\[([^\]]+)\]/', $form_content, $matches)) {
                foreach ($matches[1] as $tag) {
                    // Extract field name from tag (e.g., "text* your-name" -> "your-name")
                    // Also handle tags like [email* your-email] or [textarea your-message]
                    $tag_parts = preg_split('/\s+/', $tag, 2);
                    if (count($tag_parts) >= 2) {
                        $field_name = trim($tag_parts[1]);
                        // Remove trailing ] if present
                        $field_name = rtrim($field_name, ']');
                        if (!empty($field_name) && !in_array($field_name, $form_fields)) {
                            $form_fields[] = $field_name;
                        }
                    }
                }
            }

            // Also check form mail properties for additional fields
            $mail = $form->prop('mail');
            if (!empty($mail['body'])) {
                // Parse mail body for field tags like [your-name]
                if (preg_match_all('/\[([^\]]+)\]/', $mail['body'], $mail_matches)) {
                    foreach ($mail_matches[1] as $field) {
                        // Clean the field name
                        $field = trim($field);
                        if (!empty($field) && !in_array($field, $form_fields)) {
                            $form_fields[] = $field;
                        }
                    }
                }
            }

            // Also check additional mail properties if they exist
            $mail_2 = $form->prop('mail_2');
            if (!empty($mail_2['body'])) {
                if (preg_match_all('/\[([^\]]+)\]/', $mail_2['body'], $mail_matches)) {
                    foreach ($mail_matches[1] as $field) {
                        $field = trim($field);
                        if (!empty($field) && !in_array($field, $form_fields)) {
                            $form_fields[] = $field;
                        }
                    }
                }
            }

            if (!empty($form_fields)) {
                $fields_by_form[$form_id] = array_unique($form_fields);
            } else {
                // If no fields found with regex, try alternative approach
                $this->try_alternative_field_detection($form, $form_id, $fields_by_form);
            }
        }

        return $fields_by_form;
    }

    /**
     * Alternative method to detect form fields when regex fails
     */
    private function try_alternative_field_detection($form, $form_id, &$fields_by_form): void
    {
        $form_fields = [];

        // Try to get form tags directly
        if (method_exists($form, 'scan_form_tags')) {
            $tags = $form->scan_form_tags();
            foreach ($tags as $tag) {
                if (!empty($tag->name)) {
                    $form_fields[] = $tag->name;
                }
            }
        }

        // Try to get collected tags
        if (empty($form_fields) && method_exists($form, 'collect_mail_tags')) {
            $mail_tags = $form->collect_mail_tags();
            if (!empty($mail_tags)) {
                foreach ($mail_tags as $tag) {
                    if (!empty($tag) && !in_array($tag, $form_fields)) {
                        $form_fields[] = $tag;
                    }
                }
            }
        }

        // Try parsing the form content differently
        $form_content = $form->prop('form');
        if (!empty($form_content)) {
            // Look for patterns like [text* your-name] or [email your-email]
            if (preg_match_all('/\[(?:[^\]]+\s+)?([^\s\]]+)\]/', $form_content, $matches)) {
                foreach ($matches[1] as $field) {
                    // Skip field types like "text*", "email", "textarea", etc.
                    if (!in_array($field, [
                        'text',
                        'text*',
                        'email',
                        'email*',
                        'tel',
                        'tel*',
                        'textarea',
                        'textarea*',
                        'select',
                        'select*',
                        'checkbox',
                        'checkbox*',
                        'radio',
                        'submit'
                    ])) {
                        $form_fields[] = $field;
                    }
                }
            }
        }

        if (!empty($form_fields)) {
            $fields_by_form[$form_id] = array_unique($form_fields);
        }
    }

    /**
     * Show recent security events
     */
    private function show_recent_activity(): void
    {
        $log_file = CF7SEC_LOG_DIR . 'security_events.json';

        // Check if file exists and is readable
        if (!file_exists($log_file) || !is_readable($log_file)) {
            echo '<p>No recent activity recorded. Debug logs will appear here after form submissions with debug mode enabled.</p>';
            return;
        }

        $content = @file_get_contents($log_file);
        if ($content === false) {
            echo '<p>Unable to read activity log file. Please check file permissions.</p>';
            return;
        }

        $events = json_decode($content, true);

        if (empty($events) || !is_array($events)) {
            echo '<p>No recent activity recorded.</p>';
            return;
        }

        // Sort by timestamp (newest first)
        usort($events, function ($a, $b) {
            $time_a = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
            $time_b = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
            return $time_b - $time_a;
        });

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Time</th><th>Event</th><th>Details</th></tr></thead>';
        echo '<tbody>';

        $count = 0;
        foreach ($events as $event) {
            if ($count++ >= 20) break; // Show only 20 most recent

            $timestamp = isset($event['timestamp']) ? date('Y-m-d H:i', strtotime($event['timestamp'])) : 'Unknown';
            $event_type = isset($event['event_type']) ? $event['event_type'] : 'UNKNOWN';

            echo '<tr>';
            echo '<td>' . esc_html($timestamp) . '</td>';
            echo '<td><strong>' . esc_html($event_type) . '</strong></td>';
            echo '<td>';
            if (!empty($event['data']) && is_array($event['data'])) {
                foreach ($event['data'] as $key => $value) {
                    if (is_array($value)) {
                        echo esc_html($key) . ': ' . esc_html(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '<br>';
                    } else {
                        echo esc_html($key) . ': ' . esc_html($value) . '<br>';
                    }
                }
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Get form check history from JSON file
     */
    private function get_form_check_history(int $limit = 15): array
    {
        if (!file_exists(CF7SEC_HISTORY_FILE)) {
            return [];
        }

        $content = file_get_contents(CF7SEC_HISTORY_FILE);
        $history = json_decode($content, true) ?? [];

        // Sort by timestamp (newest first)
        usort($history, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        // Group by IP to combine multiple attempts
        $grouped_history = [];
        foreach ($history as $entry) {
            $ip = $entry['ip'] ?? 'unknown';
            $form_id = $entry['form_id'] ?? 0;
            $key = $ip . '_' . $form_id;

            if (!isset($grouped_history[$key])) {
                $grouped_history[$key] = [
                    'timestamp' => $entry['timestamp'],
                    'form_id' => $form_id,
                    'ip' => $ip,
                    'result' => $entry['result'],
                    'reasons' => $entry['reasons'] ?? [],
                    'detailed_reasons' => $entry['detailed_reasons'] ?? [],
                    'attempts' => 1,
                    'attack_types' => $entry['attack_types'] ?? [],
                    'is_duplicate' => false
                ];
            } else {
                // Combine multiple attempts from same IP
                $grouped_history[$key]['attempts']++;
                $grouped_history[$key]['is_duplicate'] = true;

                // Merge reasons and attack types
                if (!empty($entry['reasons'])) {
                    $grouped_history[$key]['reasons'] = array_merge(
                        $grouped_history[$key]['reasons'],
                        $entry['reasons']
                    );
                    $grouped_history[$key]['reasons'] = array_unique($grouped_history[$key]['reasons']);
                }

                if (!empty($entry['attack_types'])) {
                    $grouped_history[$key]['attack_types'] = array_merge(
                        $grouped_history[$key]['attack_types'],
                        $entry['attack_types']
                    );
                    $grouped_history[$key]['attack_types'] = array_unique($grouped_history[$key]['attack_types']);
                }

                // Use the most recent result if different
                if (strtotime($entry['timestamp']) > strtotime($grouped_history[$key]['timestamp'])) {
                    $grouped_history[$key]['timestamp'] = $entry['timestamp'];
                    $grouped_history[$key]['result'] = $entry['result'];
                }
            }
        }

        // Convert back to array and sort by timestamp
        $grouped_history = array_values($grouped_history);
        usort($grouped_history, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        // Apply limit
        if ($limit > 0) {
            $grouped_history = array_slice($grouped_history, 0, $limit);
        }

        return $grouped_history;
    }

    /**
     * Log form check to history
     */
    private function log_form_check(array $data): void
    {
        $history = $this->get_form_check_history(0); // Get all history

        // Add new entry
        $history[] = [
            'timestamp' => date('c'),
            'form_id' => $data['form_id'] ?? 0,
            'ip' => $data['ip'] ?? 'unknown',
            'result' => $data['result'] ?? 'UNKNOWN',
            'reasons' => $data['reasons'] ?? [],
            'detailed_reasons' => $data['detailed_reasons'] ?? [],
            'attack_types' => $data['attack_types'] ?? [],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ];

        // Keep only last 1000 entries
        if (count($history) > 1000) {
            $history = array_slice($history, -1000);
        }

        file_put_contents(CF7SEC_HISTORY_FILE, json_encode($history, JSON_PRETTY_PRINT));
    }

    /**
     * Get recent debug logs
     */
    private function get_recent_logs(int $limit = 10): array
    {
        $logs = glob(CF7SEC_LOG_DIR . 'debug_*.json');
        $recent_logs = [];

        if (empty($logs)) {
            return [];
        }

        // Sort by modification time (newest first)
        usort($logs, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $count = 0;
        foreach ($logs as $log) {
            if ($count++ >= $limit) break;

            $filename = basename($log);
            $filesize = filesize($log);

            // Try to extract form ID from filename
            $form_id = 0;
            if (preg_match('/form_(\d+)/', $filename, $matches)) {
                $form_id = $matches[1];
            }

            $recent_logs[] = [
                'filename' => $filename,
                'size' => size_format($filesize, 2),
                'modified' => date('Y-m-d H:i:s', filemtime($log)),
                'form_id' => $form_id,
                'path' => $log
            ];
        }

        return $recent_logs;
    }

    /**
     * Add hidden fields to forms
     */
    public function add_hidden_fields(array $fields): array
    {
        $options = get_option($this->options_name, []);
        $features = $options['security_features'] ?? [];

        // Add timestamp for time-based checking
        $fields['_cf7a_timestamp'] = time();

        // Add honeypot field if enabled
        if ($features['honeypot'] ?? true) {
            $current_form = wpcf7_get_current_contact_form();
            if ($current_form) {
                $form_id = $current_form->id();
                $hp_field_name = 'cf7_asc_hp_' . substr(md5((string)$form_id), 0, 8);
                $fields[$hp_field_name] = '';
            }
        }

        return $fields;
    }

    /**
     * Get CF7 forms count
     */
    private function get_protected_forms_count(): int
    {
        // Check if CF7 is active first
        if (!class_exists('WPCF7_ContactForm')) {
            return 0;
        }

        try {
            $forms = WPCF7_ContactForm::find();
            return count($forms);
        } catch (Exception $e) {
            // Fallback to get_posts if WPCF7_ContactForm::find() fails
            $forms = get_posts([
                'post_type' => 'wpcf7_contact_form',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids'
            ]);
            return count($forms);
        }
    }

    /**
     * Increment counter in database
     */
    private function increment_counter(string $counter): void
    {
        $options = get_option($this->options_name, []);
        $current = (int)($options[$counter] ?? 0);
        $options[$counter] = $current + 1;
        update_option($this->options_name, $options, false);
    }

    /**
     * Get client IP address with comprehensive proxy support and validation
     * IMPROVED: Better proxy detection and IP validation
     */
    private function get_client_ip(): string
    {
        $ip_sources = [
            'HTTP_CF_CONNECTING_IP',        // Cloudflare
            'HTTP_X_REAL_IP',               // Nginx
            'HTTP_X_FORWARDED_FOR',         // Most proxies
            'HTTP_CLIENT_IP',               // Some proxies
            'HTTP_X_CLUSTER_CLIENT_IP',     // Rackspace LB
            'HTTP_X_FORWARDED',             // General
            'HTTP_FORWARDED_FOR',           // RFC 7239
            'HTTP_FORWARDED',               // RFC 7239
            'REMOTE_ADDR'                   // Fallback
        ];

        foreach ($ip_sources as $source) {
            if (!empty($_SERVER[$source])) {
                // Handle comma-separated lists (e.g., X-Forwarded-For: client, proxy1, proxy2)
                $ip_list = explode(',', $_SERVER[$source]);

                foreach ($ip_list as $ip) {
                    $ip = trim($ip);

                    // Validate IP format
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }

                    // Also accept private IPs as fallback
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get ban list from file
     */
    private function get_ban_list(): array
    {
        $ban_file = CF7SEC_LOG_DIR . 'ban_list.json';
        if (!file_exists($ban_file)) {
            return [];
        }

        $content = file_get_contents($ban_file);
        $ban_list = json_decode($content, true) ?? [];

        // Remove expired bans
        $current_time = time();
        $updated = false;

        foreach ($ban_list as $ip => $ban) {
            if (!$ban['is_permanent'] && $current_time > $ban['expires_at']) {
                unset($ban_list[$ip]);
                $updated = true;
            }
        }

        if ($updated) {
            file_put_contents($ban_file, json_encode($ban_list, JSON_PRETTY_PRINT));
        }

        return $ban_list;
    }

    /**
     * Ban IP address with custom duration support
     * FIXED: Added $duration parameter and fixed expiration calculation
     */
    private function ban_ip(string $ip, string $reason, string $attack_type, int $custom_duration = 0): void
    {
        $ban_list = $this->get_ban_list();
        $options = get_option($this->options_name, []);

        // Use custom duration if provided, otherwise use settings
        if ($custom_duration > 0) {
            $duration = $custom_duration;
        } else {
            $duration = $options['ban_duration'] ?? self::BAN_DURATION;
        }

        // Fix expiration calculation - ensure we're adding seconds, not multiplying
        $expires_at = time() + $duration;

        // If duration is 0 or negative, make it permanent
        $is_permanent = ($duration <= 0);

        // For permanent bans, set expiration far in the future (100 years)
        if ($is_permanent) {
            $expires_at = time() + (31536000 * 100); // 100 years
        }

        $ban_list[$ip] = [
            'banned_at' => time(),
            'expires_at' => $expires_at,
            'reason' => $reason,
            'is_permanent' => $is_permanent,
            'attack_type' => $attack_type,
            'duration_used' => $duration, // Store the duration used for debugging
            'banned_by' => isset($custom_duration) && $custom_duration > 0 ? 'manual' : 'system'
        ];

        $ban_file = CF7SEC_LOG_DIR . 'ban_list.json';
        file_put_contents($ban_file, json_encode($ban_list, JSON_PRETTY_PRINT));

        // Log security event
        $this->log_security_event('IP_BANNED', [
            'ip' => $ip,
            'reason' => $reason,
            'attack_type' => $attack_type,
            'duration' => $duration,
            'is_permanent' => $is_permanent,
            'expires_at' => $expires_at,
            'expires_human' => date('Y-m-d H:i:s', $expires_at)
        ]);
    }

    /**
     * Log security event to file
     */
    private function log_security_event(string $event_type, array $data = []): void
    {
        $log_file = CF7SEC_LOG_DIR . 'security_events.json';
        $events = [];

        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            $events = json_decode($content, true) ?? [];
        }

        $events[] = [
            'timestamp' => date('c'),
            'event_type' => $event_type,
            'data' => $data
        ];

        // Keep only last 1000 events
        if (count($events) > 1000) {
            $events = array_slice($events, -1000);
        }

        file_put_contents($log_file, json_encode($events, JSON_PRETTY_PRINT));
    }

    /**
     * AJAX handler to toggle feature
     */
    public function ajax_toggle_feature(): void
    {
        check_ajax_referer('cf7sec_toggle_feature', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $feature = sanitize_key($_POST['feature']);
        $enabled = $_POST['enabled'] === '1';

        $options = get_option($this->options_name, []);
        if (!isset($options['security_features'])) {
            $options['security_features'] = [];
        }

        $options['security_features'][$feature] = $enabled;
        update_option($this->options_name, $options);

        wp_send_json_success(['message' => 'Feature updated']);
    }

    /**
     * AJAX handler to reset counter
     * FIXED: Properly resets all statistics and updates local properties
     */
    public function ajax_reset_counter(): void
    {
        check_ajax_referer('cf7sec_reset_counter', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access', 403);
        }

        $options = get_option($this->options_name, []);

        // Reset all statistics counters
        $options['processed_submissions'] = 0;
        $options['blocked_submissions'] = 0;

        // Update the option
        $result = update_option($this->options_name, $options, false);

        if ($result) {
            // Update local properties immediately
            $this->processed_submissions = 0;

            // Also reset counters in settings array for immediate use
            $this->settings['processed_submissions'] = 0;
            $this->settings['blocked_submissions'] = 0;

            wp_send_json_success([
                'message' => 'Statistics reset successfully',
                'processed_submissions' => 0,
                'blocked_submissions' => 0,
                'protected_forms' => $this->protected_forms
            ]);
        } else {
            wp_send_json_error('Failed to reset statistics. Please try again.');
        }
    }

    /**
     * AJAX handler to get statistics
     */
    public function ajax_get_stats(): void
    {
        check_ajax_referer('cf7sec_get_stats', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $options = get_option($this->options_name, []);
        $stats = [
            'protected_forms' => $this->get_protected_forms_count(),
            'processed_submissions' => (int)($options['processed_submissions'] ?? 0),
            'blocked_submissions' => (int)($options['blocked_submissions'] ?? 0),
        ];

        wp_send_json_success($stats);
    }

    /**
     * AJAX handler to manually ban IP
     * FIXED: Pass duration parameter to ban_ip() method
     */
    public function ajax_manual_ban(): void
    {
        check_ajax_referer('cf7sec_manual_ban', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access', 403);
        }

        $ip = sanitize_text_field($_POST['ip'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? 'Manual ban');
        $duration = (int)($_POST['duration'] ?? 3600); // Default to 1 hour if not specified

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address format');
        }

        // Pass the duration to ban_ip method
        $this->ban_ip($ip, $reason, 'MANUAL_BAN', $duration);
        wp_send_json_success([
            'message' => 'IP banned successfully',
            'ip' => $ip,
            'duration' => $duration
        ]);
    }

    /**
     * AJAX handler to unban IP
     */
    public function ajax_unban_ip(): void
    {
        check_ajax_referer('cf7sec_unban_ip', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $ip = sanitize_text_field($_POST['ip']);
        $ban_list = $this->get_ban_list();

        if (isset($ban_list[$ip])) {
            unset($ban_list[$ip]);
            $ban_file = CF7SEC_LOG_DIR . 'ban_list.json';
            file_put_contents($ban_file, json_encode($ban_list, JSON_PRETTY_PRINT));
            wp_send_json_success(['message' => 'IP unbanned']);
        } else {
            wp_send_json_error('IP not found in ban list');
        }
    }

    /**
     * AJAX handler to get log content with enhanced security
     * IMPROVED: Added path traversal protection and file type validation
     */
    public function ajax_get_log_content(): void
    {
        check_ajax_referer('cf7sec_get_log_content', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $filename = sanitize_file_name($_POST['filename'] ?? '');

        // Security: Only allow JSON files in the log directory
        if (strpos($filename, '..') !== false || !preg_match('/^debug_.*\.json$/', $filename)) {
            wp_send_json_error('Invalid log file');
        }

        $filepath = CF7SEC_LOG_DIR . $filename;

        // Security: Ensure file is within the log directory
        $real_log_dir = realpath(CF7SEC_LOG_DIR);
        $real_filepath = realpath($filepath);

        if ($real_filepath === false || strpos($real_filepath, $real_log_dir) !== 0) {
            wp_send_json_error('Access denied');
        }

        if (!file_exists($filepath) || !is_readable($filepath)) {
            wp_send_json_error('Log file not found or not readable');
        }

        // Security: Limit file size to prevent memory issues
        $filesize = filesize($filepath);
        if ($filesize > 10485760) { // 10MB limit
            wp_send_json_error('Log file too large');
        }

        $content = file_get_contents($filepath);

        if ($content === false) {
            wp_send_json_error('Failed to read log file');
        }

        // Try to parse JSON for pretty formatting
        $data = json_decode($content, true);

        if ($data) {
            $formatted = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            wp_send_json_success(['content' => $formatted]);
        } else {
            // Return as plain text if not valid JSON
            wp_send_json_success(['content' => $content]);
        }
    }

    /**
     * AJAX handler to delete log
     */
    public function ajax_delete_log(): void
    {
        check_ajax_referer('cf7sec_delete_log', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $filepath = CF7SEC_LOG_DIR . $filename;

        if (file_exists($filepath) && unlink($filepath)) {
            wp_send_json_success(['message' => 'Log deleted']);
        } else {
            wp_send_json_error('Failed to delete log');
        }
    }

    /**
     * AJAX handler to clear debug logs
     */
    public function ajax_clear_debug_logs(): void
    {
        check_ajax_referer('cf7sec_clear_debug_logs', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $logs = glob(CF7SEC_LOG_DIR . 'debug_*.json');
        $deleted = 0;

        foreach ($logs as $log) {
            if (unlink($log)) {
                $deleted++;
            }
        }

        wp_send_json_success(['message' => "Deleted {$deleted} log files"]);
    }

    /**
     * AJAX handler to clear form check history
     */
    public function ajax_clear_history(): void
    {
        check_ajax_referer('cf7sec_clear_history', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        // Clear history file
        file_put_contents(CF7SEC_HISTORY_FILE, json_encode([], JSON_PRETTY_PRINT));

        wp_send_json_success(['message' => 'History cleared']);
    }

    /**
     * AJAX handler to get form check history
     */
    public function ajax_get_check_history(): void
    {
        check_ajax_referer('cf7sec_get_check_history', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 15;
        $history = $this->get_form_check_history($limit);

        wp_send_json_success(['history' => $history]);
    }


    /**
     * General Settings Page
     * FIXED: Fixed ban duration display and calculation
     */
    public function general_settings_page(): void
    {
        $options = get_option($this->options_name, []);

        // Get ban duration in hours for display
        $ban_duration_seconds = $options['ban_duration'] ?? self::BAN_DURATION;
        $ban_duration_hours = intval($ban_duration_seconds / 3600);
    ?>
        <div class="wrap">
            <h1>General Settings</h1>
            <p class="description">Configure basic spam protection parameters including rate limiting and IP blocking thresholds.</p>

            <form method="post" action="options.php" class="cf7sec-settings-form">
                <?php settings_fields('cf7sec_settings'); ?>

                <div class="postbox">
                    <div class="inside">
                        <h2>Rate Limiting Configuration</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="max_requests">Maximum Requests per Minute</label></th>
                                <td>
                                    <input type="number" id="max_requests" name="cf7sec_options[max_requests]"
                                        value="<?php echo esc_attr($options['max_requests'] ?? self::MAX_REQUESTS_PER_MINUTE); ?>"
                                        min="1" max="1000" class="regular-text">
                                    <p class="description">
                                        Maximum number of form submissions allowed from a single IP address within one minute.
                                        Higher values provide more flexibility but may increase vulnerability to spam attacks.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ban_threshold">Ban Threshold</label></th>
                                <td>
                                    <input type="number" id="ban_threshold" name="cf7sec_options[ban_threshold]"
                                        value="<?php echo esc_attr($options['ban_threshold'] ?? self::BAN_THRESHOLD); ?>"
                                        min="1" max="1000" class="regular-text">
                                    <p class="description">
                                        Number of detected spam attacks from a single IP before it is permanently banned.
                                        This helps prevent persistent attackers from overwhelming your forms.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ban_duration">Temporary Ban Duration (Hours)</label></th>
                                <td>
                                    <input type="number" id="ban_duration" name="cf7sec_options[ban_duration]"
                                        value="<?php echo esc_attr($ban_duration_hours); ?>"
                                        min="0" max="8760" class="regular-text">
                                    <p class="description">
                                        Duration of temporary IP bans in hours. IPs that trigger rate limiting will be banned
                                        for this period. Set to 0 for permanent bans (use with caution).
                                    </p>
                                    <p class="description">
                                        <strong>Current setting:</strong> <?php echo $ban_duration_hours; ?> hours
                                        (<?php echo $ban_duration_seconds; ?> seconds)
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="postbox">
                    <div class="inside">
                        <h2>Form Submission Timing</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="min_submission_time">Minimum Submission Time (Seconds)</label></th>
                                <td>
                                    <input type="number" id="min_submission_time" name="cf7sec_options[min_submission_time]"
                                        value="<?php echo esc_attr($options['min_submission_time'] ?? 3); ?>"
                                        min="1" max="30" class="regular-text">
                                    <p class="description">
                                        Minimum time required between form load and submission. Prevents bots that submit forms
                                        instantly. Human users typically take at least 3-5 seconds to fill out a form.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="max_form_age">Maximum Form Age (Hours)</label></th>
                                <td>
                                    <input type="number" id="max_form_age" name="cf7sec_options[max_form_age]"
                                        value="<?php echo esc_attr($options['max_form_age'] ?? 1); ?>"
                                        min="1" max="24" class="regular-text">
                                    <p class="description">
                                        Forms older than this will be rejected. Prevents submission of stale forms that may have
                                        been saved and submitted later (common in some spam attacks).
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php submit_button('Save General Settings', 'primary', 'submit', true); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Security Features Page
     */
    public function security_features_page(): void
    {
        $options = get_option($this->options_name, []);
        $features = $options['security_features'] ?? [];
    ?>
        <div class="wrap">
            <h1>Security Features</h1>
            <p class="description">Enable or disable individual security modules to customize your protection level.</p>

            <div class="cf7sec-features-grid">
                <?php
                $security_features = [
                    'ip_block' => [
                        'label' => 'IP Blocking',
                        'description' => 'Blocks known spammer IP addresses and maintains a dynamic ban list based on attack patterns.',
                        'icon' => 'admin-network'
                    ],
                    'time_check' => [
                        'label' => 'Time-Based Validation',
                        'description' => 'Validates form submission timing to prevent instant submissions (common with bots).',
                        'icon' => 'clock'
                    ],
                    'honeypot' => [
                        'label' => 'Honeypot Field',
                        'description' => 'Adds invisible form fields that only bots will fill, identifying them immediately.',
                        'icon' => 'visibility'
                    ],
                    'sql_injection' => [
                        'label' => 'SQL Injection Protection',
                        'description' => 'Detects and blocks common SQL injection patterns in form submissions.',
                        'icon' => 'database'
                    ],
                    'xss_protection' => [
                        'label' => 'XSS Protection',
                        'description' => 'Prevents cross-site scripting attacks by sanitizing user input.',
                        'icon' => 'shield'
                    ],
                    'bot_detection' => [
                        'label' => 'Bot Detection',
                        'description' => 'Identifies bots by analyzing user-agent strings and behavior patterns.',
                        'icon' => 'robot'
                    ],
                    'rate_limiting' => [
                        'label' => 'Rate Limiting',
                        'description' => 'Limits the number of submissions per IP address within time windows.',
                        'icon' => 'chart-area'
                    ],
                    'language_validation' => [
                        'label' => 'Language Validation',
                        'description' => 'Validates name fields against selected language character sets.',
                        'icon' => 'translation'
                    ],
                ];

                foreach ($security_features as $key => $feature):
                    $enabled = isset($features[$key]) ? $features[$key] : true;
                ?>
                    <div class="feature-card">
                        <div class="feature-header">
                            <div class="feature-icon">
                                <span class="dashicons dashicons-<?php echo esc_attr($feature['icon']); ?>"></span>
                            </div>
                            <div class="feature-title">
                                <h3><?php echo esc_html($feature['label']); ?></h3>
                            </div>
                            <div class="feature-toggle">
                                <label class="cf7sec-toggle-switch">
                                    <input type="checkbox" class="cf7sec-feature-toggle"
                                        data-feature="<?php echo esc_attr($key); ?>"
                                        <?php checked($enabled); ?>>
                                    <span class="cf7sec-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="feature-body">
                            <p><?php echo esc_html($feature['description']); ?></p>
                        </div>
                        <div class="feature-footer">
                            <span class="feature-status <?php echo $enabled ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo $enabled ? 'Active' : 'Inactive'; ?>
                            </span>
                            <?php if ($enabled): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="postbox" style="margin-top: 30px;">
                <div class="inside">
                    <h2>Feature Dependencies</h2>
                    <p>Some features work better when combined:</p>
                    <ul>
                        <li><strong>Rate Limiting + IP Blocking:</strong> Temporary bans become permanent after threshold</li>
                        <li><strong>Time Check + Honeypot:</strong> Multiple layers of bot detection</li>
                        <li><strong>Language Validation + XSS Protection:</strong> Comprehensive input validation</li>
                    </ul>
                    <p class="description">All changes are saved automatically when toggling features.</p>
                </div>
            </div>
        </div>

        <style>
            .cf7sec-features-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }

            .feature-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                overflow: hidden;
                transition: all 0.3s ease;
            }

            .feature-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }

            .feature-header {
                display: flex;
                align-items: center;
                padding: 20px;
                background: #f8f9fa;
                border-bottom: 1px solid #e0e0e0;
            }

            .feature-icon {
                margin-right: 15px;
            }

            .feature-icon .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                color: #2271b1;
            }

            .feature-title {
                flex: 1;
            }

            .feature-title h3 {
                margin: 0;
                color: #333;
            }

            .feature-body {
                padding: 20px;
                border-bottom: 1px solid #eee;
            }

            .feature-body p {
                margin: 0;
                color: #666;
                line-height: 1.6;
            }

            .feature-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 20px;
                background: #f8f9fa;
            }

            .feature-status {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }

            .status-enabled {
                background: #d4edda;
                color: #155724;
            }

            .status-disabled {
                background: #f8d7da;
                color: #721c24;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                $('.cf7sec-feature-toggle').on('change', function() {
                    var $toggle = $(this);
                    var feature = $toggle.data('feature');
                    var enabled = $toggle.is(':checked');
                    var $card = $toggle.closest('.feature-card');
                    var $status = $card.find('.feature-status');
                    var $icon = $card.find('.feature-footer .dashicons');

                    // Update UI immediately
                    $status
                        .text(enabled ? 'Active' : 'Inactive')
                        .removeClass('status-enabled status-disabled')
                        .addClass(enabled ? 'status-enabled' : 'status-disabled');

                    $icon
                        .removeClass('dashicons-yes-alt dashicons-no-alt')
                        .addClass(enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt')
                        .css('color', enabled ? '#46b450' : '#dc3232');

                    // Save to server
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'cf7sec_toggle_feature',
                            feature: feature,
                            enabled: enabled ? 1 : 0,
                            nonce: '<?php echo wp_create_nonce("cf7sec_toggle_feature"); ?>'
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * IP Management Page
     */
    public function ip_management_page(): void
    {
        $ban_list = $this->get_ban_list();
    ?>
        <div class="wrap">
            <h1>IP Address Management</h1>

            <div class="cf7sec-ip-management">
                <!-- Manual Ban Form -->
                <div class="postbox" style="margin: 20px 0; border: 1px solid #ddd; background: white;">
                    <div class="inside" style="padding: 20px;">
                        <h2 class="hndle">Manual IP Ban</h2>
                        <form id="manual-ban-form">
                            <table class="form-table">
                                <tr>
                                    <th><label for="ban-ip">IP Address</label></th>
                                    <td>
                                        <input type="text" id="ban-ip" name="ip" class="regular-text"
                                            pattern="^(\d{1,3}\.){3}\d{1,3}$" required>
                                        <p class="description">Enter a valid IPv4 address (e.g., 192.168.1.1)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="ban-reason">Reason</label></th>
                                    <td>
                                        <input type="text" id="ban-reason" name="reason" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="ban-duration">Duration</label></th>
                                    <td>
                                        <select id="ban-duration" name="duration" class="regular-text">
                                            <option value="3600">1 Hour</option>
                                            <option value="86400">1 Day</option>
                                            <option value="604800">1 Week</option>
                                            <option value="2592000">1 Month</option>
                                            <option value="0">Permanent</option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                            <p>
                                <button type="submit" class="button button-primary">Ban IP Address</button>
                                <span id="ban-message" style="display:none; margin-left:10px; color:green;"></span>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Current Bans -->
                <div class="postbox" style="margin: 20px 0; border: 1px solid #ddd; background: white;">
                    <div class="inside" style="padding: 20px;">
                        <h2 class="hndle">Currently Banned IPs</h2>
                        <?php if (empty($ban_list)): ?>
                            <p>No IP addresses are currently banned.</p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>IP Address</th>
                                        <th>Reason</th>
                                        <th>Banned At</th>
                                        <th>Expires</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ban_list as $ip => $ban): ?>
                                        <tr>
                                            <td><code><?php echo esc_html($ip); ?></code></td>
                                            <td><?php echo esc_html($ban['reason']); ?></td>
                                            <td><?php echo date('Y-m-d H:i', $ban['banned_at']); ?></td>
                                            <td>
                                                <?php if ($ban['is_permanent']): ?>
                                                    <span style="color:red;">Permanent</span>
                                                <?php else: ?>
                                                    <?php echo date('Y-m-d H:i', $ban['expires_at']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small unban-ip"
                                                    data-ip="<?php echo esc_attr($ip); ?>">
                                                    Unban
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <script>
                jQuery(document).ready(function($) {
                    // Manual ban form
                    $('#manual-ban-form').on('submit', function(e) {
                        e.preventDefault();

                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'cf7sec_manual_ban',
                                ip: $('#ban-ip').val(),
                                reason: $('#ban-reason').val(),
                                duration: $('#ban-duration').val(),
                                nonce: '<?php echo wp_create_nonce("cf7sec_manual_ban"); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#ban-message').text('IP banned successfully').show().fadeOut(3000);
                                    $('#manual-ban-form')[0].reset();
                                    location.reload();
                                }
                            }
                        });
                    });

                    // Unban IP
                    $('.unban-ip').on('click', function() {
                        var ip = $(this).data('ip');
                        if (confirm('Unban IP ' + ip + '?')) {
                            $.ajax({
                                url: ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'cf7sec_unban_ip',
                                    ip: ip,
                                    nonce: '<?php echo wp_create_nonce("cf7sec_unban_ip"); ?>'
                                },
                                success: function() {
                                    location.reload();
                                }
                            });
                        }
                    });
                });
            </script>
        </div>
    <?php
    }

    /**
     * Statistics Page
     * FIXED: Use real-time data from local properties
     */
    public function statistics_page(): void
    {
        $options = get_option($this->options_name, []);

        // FIXED: Use local properties for real-time statistics
        $processed_submissions = $this->processed_submissions;
        $blocked_submissions = $options['blocked_submissions'] ?? 0;
        $success_rate = $processed_submissions > 0 ?
            round(($processed_submissions - $blocked_submissions) / $processed_submissions * 100, 2) : 0;

        $stats = [
            'total_forms' => $this->protected_forms,
            'processed' => $processed_submissions,
            'blocked' => $blocked_submissions,
            'success_rate' => $success_rate
        ];
    ?>
        <div class="wrap">
            <h1>Security Statistics</h1>

            <div class="cf7sec-stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <h3>Protected Forms</h3>
                        <p class="stat-number"><?php echo $stats['total_forms']; ?></p>
                        <p class="stat-desc">Total CF7 forms on site</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <h3>Processed Submissions</h3>
                        <p class="stat-number"><?php echo $stats['processed']; ?></p>
                        <p class="stat-desc">Total spam + non-spam checks</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🛡️</div>
                    <div class="stat-content">
                        <h3>Blocked Spam</h3>
                        <p class="stat-number"><?php echo $stats['blocked']; ?></p>
                        <p class="stat-desc">Successfully blocked submissions</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <h3>Success Rate</h3>
                        <p class="stat-number"><?php echo $stats['success_rate']; ?>%</p>
                        <p class="stat-desc">Clean submissions rate</p>
                    </div>
                </div>
            </div>

            <div class="postbox" style="margin: 30px 0; border: 1px solid #ddd; background: white;">
                <div class="inside" style="padding: 20px;">
                    <h2 class="hndle">Recent Activity</h2>
                    <?php $this->show_recent_activity(); ?>
                </div>
            </div>

            <div class="postbox" style="margin: 30px 0; border: 1px solid #ddd; background: white;">
                <div class="inside" style="padding: 20px;">
                    <h2 class="hndle">Reset Statistics</h2>
                    <p>
                        <button type="button" class="button button-secondary" id="reset-stats">
                            Reset All Statistics
                        </button>
                        <span id="reset-message" style="display:none; margin-left:10px; color:green;"></span>
                        <span id="reset-error" style="display:none; margin-left:10px; color:red;"></span>
                    </p>
                    <p class="description">
                        This will reset all counters (processed submissions, blocked spam, etc.) but will not affect IP bans or settings.
                    </p>
                </div>
            </div>
        </div>

        <style>
            .cf7sec-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }

            .stat-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 10px;
                padding: 25px;
                text-align: center;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            }

            .stat-icon {
                font-size: 40px;
                margin-bottom: 15px;
            }

            .stat-content h3 {
                margin: 0 0 10px;
                color: #333;
                font-size: 16px;
            }

            .stat-number {
                font-size: 2.5em;
                font-weight: bold;
                margin: 10px 0;
                color: #2271b1;
            }

            .stat-desc {
                margin: 0;
                color: #666;
                font-size: 14px;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                $('#reset-stats').on('click', function() {
                    if (confirm('Are you sure you want to reset all statistics? This action cannot be undone.')) {
                        var $button = $(this);
                        var $message = $('#reset-message');
                        var $error = $('#reset-error');

                        $button.prop('disabled', true).text('Resetting...');
                        $message.hide();
                        $error.hide();

                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'cf7sec_reset_counter',
                                nonce: '<?php echo wp_create_nonce("cf7sec_reset_counter"); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $message.text(response.data.message || 'Statistics reset successfully!').show();

                                    // Update statistics display immediately
                                    $('.stat-card:nth-child(2) .stat-number').text('0');
                                    $('.stat-card:nth-child(3) .stat-number').text('0');
                                    $('.stat-card:nth-child(4) .stat-number').text('100%');

                                    // Reload page after 1 second to ensure consistency
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else {
                                    $error.text('Error: ' + (response.data || 'Unknown error')).show();
                                    $button.prop('disabled', false).text('Reset All Statistics');
                                }
                            },
                            error: function() {
                                $error.text('Network error. Please try again.').show();
                                $button.prop('disabled', false).text('Reset All Statistics');
                            }
                        });
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Update forms count when form is saved
     */
    public function update_forms_count($contact_form): void
    {
        $this->protected_forms = $this->get_protected_forms_count();
    }

    /**
     * Track form deletion
     */
    public function track_form_deletion(int $post_id): void
    {
        if (get_post_type($post_id) === 'wpcf7_contact_form') {
            $this->protected_forms = $this->get_protected_forms_count();
        }
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts(string $hook): void
    {
        // Only load on our plugin pages
        if (strpos($hook, 'cf7-security') === false) {
            return;
        }

        // Add inline styles for better performance
        add_action('admin_head', function () {
        ?>
            <style>
                .cf7sec-admin-notice {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 20px;
                    border-radius: 5px;
                    margin: 20px 0;
                }

                .cf7sec-admin-notice h2 {
                    margin-top: 0;
                    color: white;
                }

                /* Toggle switch styles */
                .cf7sec-toggle-switch {
                    position: relative;
                    display: inline-block;
                    width: 50px;
                    height: 24px;
                    margin-left: 10px;
                }

                .cf7sec-toggle-switch input {
                    opacity: 0;
                    width: 0;
                    height: 0;
                    position: absolute;
                }

                .cf7sec-toggle-switch .cf7sec-toggle-slider {
                    position: absolute;
                    cursor: pointer;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background-color: #ccc;
                    transition: .4s;
                    border-radius: 34px;
                }

                .cf7sec-toggle-switch .cf7sec-toggle-slider:before {
                    position: absolute;
                    content: "";
                    height: 16px;
                    width: 16px;
                    left: 4px;
                    bottom: 4px;
                    background-color: white;
                    transition: .4s;
                    border-radius: 50%;
                }

                .cf7sec-toggle-switch input:checked+.cf7sec-toggle-slider {
                    background-color: #2196F3 !important;
                }

                .cf7sec-toggle-switch input:checked+.cf7sec-toggle-slider:before {
                    transform: translateX(26px);
                }

                .cf7sec-toggle-switch-large {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                    font-size: 16px;
                    font-weight: bold;
                }

                .cf7sec-toggle-switch-large .cf7sec-toggle-switch {
                    transform: scale(1.3);
                }

                .cf7sec-toggle-switch-large .cf7sec-toggle-label {
                    font-weight: 600;
                    color: #333;
                }
            </style>
        <?php
        });
    }

    public function add_cf7_integration_panel($panels): array
    {
        $panels['cf7-security-pro'] = [
            'title' => 'CF7 Security Pro',
            'callback' => [$this, 'render_cf7_integration_panel']
        ];
        return $panels;
    }

    public function render_cf7_integration_panel($post): void
    {
        ?>
        <div id="cf7sec-integration-panel" class="cf7sec-integration-panel">
            <h2>CF7 Advanced Security Pro v<?php echo CF7SEC_VERSION; ?></h2>

            <div class="integration-header">
                <p>Advanced spam protection for Contact Form 7 with language validation, IP management, and detailed logging.</p>

                <div class="integration-links">
                    <a href="<?php echo admin_url('admin.php?page=cf7-security-dashboard'); ?>" class="button button-primary">
                        <span class="dashicons dashicons-admin-settings"></span> Plugin Dashboard
                    </a>
                    <a href="https://deynekin.com" target="_blank" class="button">
                        <span class="dashicons dashicons-external"></span> Visit Deynekin.com
                    </a>
                    <a href="https://github.com/paulmann/Contact-Form-7-Spam-Checker" target="_blank" class="button">
                        <span class="dashicons dashicons-external"></span> View on GitHub
                    </a>
                </div>
            </div>

            <div class="integration-features">
                <h3>Plugin Features:</h3>
                <div class="features-grid">
                    <div class="feature-item">
                        <span class="dashicons dashicons-translation"></span>
                        <strong>Language Validation</strong>
                        <p>Validate name fields against 22+ languages</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-database"></span>
                        <strong>SQL Injection Protection</strong>
                        <p>Block SQL injection attempts</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-shield"></span>
                        <strong>XSS Protection</strong>
                        <p>Prevent cross-site scripting attacks</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-robot"></span>
                        <strong>Bot Detection</strong>
                        <p>Detect and block bots</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-chart-area"></span>
                        <strong>Rate Limiting</strong>
                        <p>Limit form submissions per IP</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-admin-network"></span>
                        <strong>IP Management</strong>
                        <p>Block and whitelist IP addresses</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-search"></span>
                        <strong>Debug Logging</strong>
                        <p>Detailed logs for troubleshooting</p>
                    </div>
                    <div class="feature-item">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <strong>Real-time Statistics</strong>
                        <p>Track protected forms and submissions</p>
                    </div>
                </div>
            </div>

            <div class="integration-stats">
                <h3>Current Protection Status:</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $this->protected_forms; ?></div>
                        <div class="stat-label">Protected Forms</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $this->processed_submissions; ?></div>
                        <div class="stat-label">Processed Submissions</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo get_option($this->options_name, [])['blocked_submissions'] ?? 0; ?></div>
                        <div class="stat-label">Blocked Spam</div>
                    </div>
                </div>
            </div>

            <div class="integration-help">
                <h3>Need Help?</h3>
                <p>For documentation, troubleshooting, or feature requests, please visit:</p>
                <ul>
                    <li><a href="https://github.com/paulmann/Contact-Form-7-Spam-Checker" target="_blank">GitHub Repository</a></li>
                    <li><a href="https://deynekin.com" target="_blank">Deynekin.com</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=cf7-security-debug'); ?>">Debug Logs</a></li>
                </ul>
            </div>
        </div>

        <style>
            .cf7sec-integration-panel {
                padding: 20px;
                background: #f8f9fa;
                border-radius: 8px;
                margin: 20px 0;
                border: 1px solid #ddd;
            }

            .cf7sec-integration-panel h2 {
                margin-top: 0;
                color: #2271b1;
                padding-bottom: 15px;
                border-bottom: 2px solid #2271b1;
            }

            .cf7sec-integration-panel h3 {
                color: #444;
                margin: 25px 0 15px;
            }

            .integration-header {
                background: white;
                padding: 20px;
                border-radius: 5px;
                margin: 20px 0;
                border: 1px solid #e0e0e0;
            }

            .integration-header p {
                margin: 0 0 15px;
                font-size: 15px;
                line-height: 1.5;
            }

            .integration-links {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .integration-links .button {
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }

            .integration-features {
                background: white;
                padding: 20px;
                border-radius: 5px;
                border: 1px solid #e0e0e0;
            }

            .features-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 15px;
            }

            .feature-item {
                padding: 15px;
                background: #f8f9fa;
                border-radius: 5px;
                border-left: 3px solid #2271b1;
            }

            .feature-item .dashicons {
                color: #2271b1;
                font-size: 24px;
                width: 24px;
                height: 24px;
                margin-bottom: 10px;
            }

            .feature-item strong {
                display: block;
                margin-bottom: 5px;
                color: #333;
            }

            .feature-item p {
                margin: 0;
                color: #666;
                font-size: 13px;
            }

            .integration-stats {
                background: white;
                padding: 20px;
                border-radius: 5px;
                border: 1px solid #e0e0e0;
                margin-top: 20px;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 20px;
                margin-top: 15px;
            }

            .stat-item {
                text-align: center;
                padding: 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 8px;
                color: white;
            }

            .stat-value {
                font-size: 2.5em;
                font-weight: bold;
                margin-bottom: 5px;
            }

            .stat-label {
                font-size: 14px;
                opacity: 0.9;
            }

            .integration-help {
                background: white;
                padding: 20px;
                border-radius: 5px;
                border: 1px solid #e0e0e0;
                margin-top: 20px;
            }

            .integration-help ul {
                margin: 10px 0;
                padding-left: 20px;
            }

            .integration-help li {
                margin: 5px 0;
            }

            .integration-help a {
                color: #2271b1;
                text-decoration: none;
            }

            .integration-help a:hover {
                text-decoration: underline;
            }
        </style>
    <?php
    }

    /**
     * Dashboard widget
     */
    public function dashboard_widget(): void
    {
        wp_add_dashboard_widget(
            'cf7sec_dashboard_widget',
            'CF7 Security Stats',
            [$this, 'render_dashboard_widget']
        );
    }

    public function render_dashboard_widget(): void
    {
        $options = get_option($this->options_name, []);
        $recent_history = $this->get_form_check_history(5);
    ?>
        <div class="cf7sec-dashboard-widget">
            <div class="dashboard-stats">
                <div class="stat">
                    <span class="stat-label">Protected Forms:</span>
                    <span class="stat-value"><?php echo $this->protected_forms; ?></span>
                </div>
                <div class="stat">
                    <span class="stat-label">Processed:</span>
                    <span class="stat-value"><?php echo $this->processed_submissions; ?></span>
                </div>
                <div class="stat">
                    <span class="stat-label">Blocked:</span>
                    <span class="stat-value"><?php echo $options['blocked_submissions'] ?? 0; ?></span>
                </div>
                <div class="stat">
                    <span class="stat-label">Success Rate:</span>
                    <span class="stat-value">
                        <?php
                        $blocked = $options['blocked_submissions'] ?? 0;
                        $processed = $this->processed_submissions;
                        echo $processed > 0 ? round(($processed - $blocked) / $processed * 100) : 100;
                        ?>%
                    </span>
                </div>
            </div>

            <?php if (!empty($recent_history)): ?>
                <div class="recent-activity">
                    <h4>Recent Activity:</h4>
                    <ul>
                        <?php foreach ($recent_history as $entry): ?>
                            <li>
                                <span class="activity-time"><?php echo date('H:i', strtotime($entry['timestamp'])); ?></span>
                                <span class="activity-result result-<?php echo strtolower($entry['result']); ?>">
                                    <?php echo $entry['result']; ?>
                                </span>
                                <?php if ($entry['attempts'] > 1): ?>
                                    <span class="activity-attempts">(<?php echo $entry['attempts']; ?>x)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="dashboard-actions">
                <a href="<?php echo admin_url('admin.php?page=cf7-security-dashboard'); ?>" class="button button-primary">
                    Dashboard
                </a>
                <a href="<?php echo admin_url('admin.php?page=cf7-security-stats'); ?>" class="button">
                    View Stats
                </a>
            </div>
        </div>

        <style>
            .cf7sec-dashboard-widget .dashboard-stats {
                margin: 15px 0;
            }

            .cf7sec-dashboard-widget .stat {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }

            .cf7sec-dashboard-widget .stat:last-child {
                border-bottom: none;
            }

            .cf7sec-dashboard-widget .stat-value {
                font-weight: bold;
                color: #2271b1;
            }

            .cf7sec-dashboard-widget .recent-activity {
                margin: 15px 0;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 5px;
            }

            .cf7sec-dashboard-widget .recent-activity h4 {
                margin: 0 0 8px;
                font-size: 14px;
            }

            .cf7sec-dashboard-widget .recent-activity ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .cf7sec-dashboard-widget .recent-activity li {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
                font-size: 12px;
            }

            .cf7sec-dashboard-widget .activity-time {
                color: #666;
            }

            .cf7sec-dashboard-widget .activity-result {
                font-weight: bold;
            }

            .cf7sec-dashboard-widget .activity-result.result-clean {
                color: #155724;
            }

            .cf7sec-dashboard-widget .activity-result.result-blocked {
                color: #721c24;
            }

            .cf7sec-dashboard-widget .activity-attempts {
                color: #6c757d;
            }

            .cf7sec-dashboard-widget .dashboard-actions {
                margin-top: 15px;
                display: flex;
                gap: 10px;
            }
        </style>
<?php
    }

    /**
     * Show recent log files in debug mode
     */
    private function show_recent_logs(): void
    {
        $logs = glob(CF7SEC_LOG_DIR . 'debug_*.json');
        if (empty($logs)) {
            echo '<p>No debug logs found.</p>';
            return;
        }

        // Sort by modification time (newest first)
        usort($logs, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        echo '<ul>';
        $count = 0;
        foreach ($logs as $log) {
            if ($count++ >= 10) break; // Show only 10 most recent

            $filename = basename($log);
            $filesize = filesize($log);
            $modified = date('Y-m-d H:i:s', filemtime($log));

            echo '<li>';
            echo '<code>' . esc_html($filename) . '</code> ';
            echo '(' . size_format($filesize, 2) . ') ';
            echo ' - ' . esc_html($modified);
            echo ' <a href="#" onclick="viewLog(\'' . esc_js($filename) . '\')">View</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * AJAX handler to save settings - updated to handle error messages
     */
    public function ajax_save_settings(): void
    {
        check_ajax_referer('cf7sec_save_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $settings = json_decode(stripslashes($_POST['settings']), true);
        $options = get_option($this->options_name, []);

        // Merge settings
        foreach ($settings as $key => $value) {
            // Special handling for error messages to preserve defaults
            if ($key === 'error_messages') {
                $options[$key] = array_merge(self::DEFAULT_ERROR_MESSAGES, $value);
            } else {
                $options[$key] = $value;
            }
        }

        update_option($this->options_name, $options);
        wp_send_json_success(['message' => 'Settings saved']);
    }

    /**
     * Sanitize settings before saving - updated for error messages
     * FIXED: Added null input handling and fixed ban duration calculation
     */
    public function sanitize_settings(?array $input): array
    {
        // Handle null input (when all checkboxes are unchecked)
        if ($input === null) {
            $input = [];
        }

        $sanitized = [];
        $current_options = get_option($this->options_name, []);

        // Debug logging for troubleshooting
        if ($this->debug_mode) {
            error_log('CF7 Security Pro: Sanitizing settings - Input: ' . json_encode($input));
        }

        // Sanitize security features
        if (isset($input['security_features'])) {
            foreach ($input['security_features'] as $key => $value) {
                $sanitized['security_features'][$key] = (bool)$value;
            }
        }

        // Sanitize language settings
        if (isset($input['language_settings'])) {
            $sanitized['language_settings'] = [
                'enabled' => isset($input['language_settings']['enabled']),
                'selected_language' => sanitize_text_field($input['language_settings']['selected_language'] ?? 'russian'),
                'custom_fields' => sanitize_text_field($input['language_settings']['custom_fields'] ?? ''),
                'strict_mode' => isset($input['language_settings']['strict_mode']),
                'validation_error_message' => sanitize_textarea_field(
                    $input['language_settings']['validation_error_message'] ??
                        self::DEFAULT_ERROR_MESSAGES['language_validation']
                ),
            ];
        }

        // Sanitize error messages
        if (isset($input['error_messages'])) {
            foreach ($input['error_messages'] as $key => $message) {
                $sanitized['error_messages'][$key] = sanitize_textarea_field($message);
            }
        }

        // Sanitize other settings - handle missing checkboxes
        $sanitized['debug_mode'] = isset($input['debug_mode']) && $input['debug_mode'] === '1';
        $sanitized['debug_output'] = isset($input['debug_output']) && $input['debug_output'] === '1';

        // Sanitize max requests
        $sanitized['max_requests'] = min(max((int)($input['max_requests'] ?? self::MAX_REQUESTS_PER_MINUTE), 1), 1000);

        // Sanitize ban threshold
        $sanitized['ban_threshold'] = min(max((int)($input['ban_threshold'] ?? self::BAN_THRESHOLD), 1), 1000);

        // FIXED: Properly handle ban duration conversion from hours to seconds
        $ban_duration_hours = (int)($input['ban_duration'] ?? 1);
        $sanitized['ban_duration'] = $ban_duration_hours * 3600;

        // Log the conversion for debugging
        if ($this->debug_mode) {
            error_log(sprintf(
                'CF7 Security Pro: Ban duration - Input: %d hours, Output: %d seconds',
                $ban_duration_hours,
                $sanitized['ban_duration']
            ));
        }

        // Merge with existing options to preserve any missing fields
        $merged_options = array_merge($current_options, $sanitized);

        return $merged_options;
    }
} // End of CF7_Advanced_Security_Pro class

// Initialize the plugin
new CF7_Advanced_Security_Pro();
