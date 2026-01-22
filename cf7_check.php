<?php
/**
 * 1st CF7 Form Checker - Advanced Security Plugin for Contact Form 7
 * Version: 3.0.0
 * Last Modified: 2025-12-17
 */

declare(strict_types=1);

// Prevent direct access
defined('ABSPATH') or die('Direct access not allowed');

// Plugin constants
define('CF7FC_VERSION', '3.0.0');
define('CF7FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CF7FC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CF7FC_LOG_DIR', WP_CONTENT_DIR . '/cf7fc_logs/');

final class CF7_Advanced_Security
{
    // Language validation settings
    private const LANGUAGES = [
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
    ];

    // Validation constants
    private const MIN_PHONE_DIGITS = 8;
    private const MAX_PHONE_DIGITS = 17;
    private const MIN_NAME_LENGTH = 2;
    private const MAX_NAME_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 254;
    private const MAX_TEXT_LENGTH = 5000;
    private const MAX_FILE_SIZE_MB = 10;
    
    // Field patterns
    private const NAME_FIELD_PATTERNS = ['name', 'имя', 'fullname', 'fio'];
    private const PHONE_FIELD_PATTERNS = ['phone', 'tel', 'телефон', 'mobile'];
    private const EMAIL_FIELD_PATTERNS = ['email', 'e-mail', 'mail', 'почта'];
    
    // Paths
    private const ATTACK_LOG_FILE = 'security_incidents.json';
    private const SETTINGS_FILE = 'settings.json';
    private const BAN_LIST_FILE = 'ban_list.json';
    private const WHITE_LIST_FILE = 'white_list.json';
    
    // Rate limiting
    private const MAX_REQUESTS_PER_MINUTE = 20;
    private const BAN_THRESHOLD = 50;
    private const BAN_DURATION = 3600;
    
    // Attack patterns (truncated for brevity)
    private const SQL_INJECTION_PATTERNS = [/*...*/];
    private const XSS_PATTERNS = [/*...*/];
    private const BOT_USER_AGENTS = [/*...*/];
    
    private array $settings = [];
    private string $clientIP;
    private string $userAgent;
    private array $securityEvents = [];
    private bool $isAttackDetected = false;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initialize();
        
        if ($this->isContactForm7Active()) {
            $this->loadSettings();
            $this->registerHooks();
        }
    }
    
    /**
     * Initialize plugin
     */
    private function initialize(): void
    {
        // Create log directory if it doesn't exist
        if (!file_exists(CF7FC_LOG_DIR)) {
            wp_mkdir_p(CF7FC_LOG_DIR);
        }
        
        $this->clientIP = $this->getClientIP();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Add admin menu
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu(): void
    {
        add_menu_page(
            '1st CF7 Form Checker',
            'CF7 Security',
            'manage_options',
            'cf7-security',
            [$this, 'renderAdminPage'],
            'dashicons-shield',
            80
        );
        
        add_submenu_page(
            'cf7-security',
            'Security Dashboard',
            'Dashboard',
            'manage_options',
            'cf7-security',
            [$this, 'renderAdminPage']
        );
        
        add_submenu_page(
            'cf7-security',
            'IP Management',
            'IP Management',
            'manage_options',
            'cf7-security-ip',
            [$this, 'renderIpManagementPage']
        );
        
        add_submenu_page(
            'cf7-security',
            'Settings',
            'Settings',
            'manage_options',
            'cf7-security-settings',
            [$this, 'renderSettingsPage']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if (strpos($hook, 'cf7-security') === false) {
            return;
        }
        
        // Enqueue Tailwind CSS from CDN
        wp_enqueue_style(
            'tailwind-css',
            'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
            [],
            '2.2.19'
        );
        
        // Enqueue custom styles
        wp_enqueue_style(
            'cf7fc-admin',
            CF7FC_PLUGIN_URL . 'assets/admin.css',
            [],
            CF7FC_VERSION
        );
        
        // Enqueue custom JavaScript
        wp_enqueue_script(
            'cf7fc-admin',
            CF7FC_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            CF7FC_VERSION,
            true
        );
    }
    
    /**
     * Render admin dashboard
     */
    public function renderAdminPage(): void
    {
        $report = $this->getSecurityReport();
        $recentBans = $this->getRecentBans(5);
        
        ?>
        <div class="wrap cf7fc-dashboard">
            <div class="bg-white shadow rounded-lg">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">
                                <span class="text-blue-600">1st</span> CF7 Form Checker
                            </h1>
                            <p class="text-gray-600 mt-1">Advanced security for Contact Form 7</p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                                Version <?php echo CF7FC_VERSION; ?>
                            </span>
                            <span class="px-3 py-1 <?php echo $report['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?> rounded-full text-sm font-medium">
                                <?php echo $report['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Grid -->
                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        <!-- Total Attacks Card -->
                        <div class="bg-gradient-to-r from-red-50 to-orange-50 border border-red-100 rounded-xl p-6">
                            <div class="flex items-center">
                                <div class="p-3 bg-red-100 rounded-lg">
                                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Total Attacks</p>
                                    <p class="text-2xl font-bold text-gray-800"><?php echo number_format($report['total_events']); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 24h Attacks Card -->
                        <div class="bg-gradient-to-r from-orange-50 to-yellow-50 border border-orange-100 rounded-xl p-6">
                            <div class="flex items-center">
                                <div class="p-3 bg-orange-100 rounded-lg">
                                    <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Attacks (24h)</p>
                                    <p class="text-2xl font-bold text-gray-800"><?php echo number_format($report['events_last_24h']); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Banned IPs Card -->
                        <div class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-100 rounded-xl p-6">
                            <div class="flex items-center">
                                <div class="p-3 bg-purple-100 rounded-lg">
                                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                                    </svg>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Banned IPs</p>
                                    <p class="text-2xl font-bold text-gray-800"><?php echo number_format(count($report['banned_ips'])); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Protected Forms Card -->
                        <div class="bg-gradient-to-r from-blue-50 to-cyan-50 border border-blue-100 rounded-xl p-6">
                            <div class="flex items-center">
                                <div class="p-3 bg-blue-100 rounded-lg">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600">Protected Forms</p>
                                    <p class="text-2xl font-bold text-gray-800"><?php echo $report['protected_forms']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Bans Section -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-gray-800">Recent Banned IPs</h2>
                            <a href="<?php echo admin_url('admin.php?page=cf7-security-ip'); ?>" 
                               class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View All →
                            </a>
                        </div>
                        
                        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Banned At</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expires</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (empty($recentBans)): ?>
                                            <tr>
                                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                                    No banned IPs found
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recentBans as $ban): ?>
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <div class="text-sm font-medium text-gray-900 font-mono">
                                                                <?php echo esc_html($ban['ip']); ?>
                                                            </div>
                                                            <?php if ($ban['is_permanent']): ?>
                                                                <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                                    Permanent
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <div class="text-sm text-gray-900">
                                                            <?php echo esc_html($ban['reason']); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo esc_html($ban['attack_type']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo esc_html(date('Y-m-d H:i', strtotime($ban['banned_at']))); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php if ($ban['is_permanent']): ?>
                                                            <span class="text-sm text-red-600 font-medium">Never</span>
                                                        <?php else: ?>
                                                            <span class="text-sm text-gray-500">
                                                                <?php echo esc_html(date('Y-m-d H:i', strtotime($ban['expires_at']))); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <button onclick="unbanIP('<?php echo esc_js($ban['ip']); ?>')" 
                                                                class="text-green-600 hover:text-green-900 mr-3">
                                                            Unban
                                                        </button>
                                                        <?php if (!$ban['is_permanent']): ?>
                                                            <button onclick="makePermanent('<?php echo esc_js($ban['ip']); ?>')" 
                                                                    class="text-red-600 hover:text-red-900">
                                                                Make Permanent
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attack Statistics -->
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800 mb-4">Attack Statistics (Last 7 Days)</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Attack Types -->
                            <div class="bg-white border border-gray-200 rounded-xl p-6">
                                <h3 class="text-md font-medium text-gray-800 mb-4">Attack Types Distribution</h3>
                                <div class="space-y-3">
                                    <?php foreach ($report['attack_types'] as $type => $count): ?>
                                        <?php if ($count > 0): ?>
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm text-gray-600"><?php echo esc_html($type); ?></span>
                                                <div class="flex items-center">
                                                    <span class="text-sm font-medium text-gray-800 mr-2"><?php echo $count; ?></span>
                                                    <div class="w-32 bg-gray-200 rounded-full h-2">
                                                        <?php 
                                                        $total = array_sum($report['attack_types']);
                                                        $width = $total > 0 ? ($count / $total * 100) : 0;
                                                        $colors = [
                                                            'SQL_INJECTION' => 'bg-red-600',
                                                            'XSS_ATTACK' => 'bg-orange-600',
                                                            'CSRF_ATTEMPT' => 'bg-yellow-600',
                                                            'RATE_LIMIT_EXCEEDED' => 'bg-purple-600',
                                                            'BOT_DETECTED' => 'bg-blue-600',
                                                        ];
                                                        $color = $colors[$type] ?? 'bg-gray-600';
                                                        ?>
                                                        <div class="h-2 rounded-full <?php echo $color; ?>" 
                                                             style="width: <?php echo $width; ?>%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- System Status -->
                            <div class="bg-white border border-gray-200 rounded-xl p-6">
                                <h3 class="text-md font-medium text-gray-800 mb-4">System Status</h3>
                                <div class="space-y-4">
                                    <?php $features = [
                                        'russian_validation' => 'Russian Validation',
                                        'rate_limiting' => 'Rate Limiting',
                                        'sql_injection' => 'SQL Injection Protection',
                                        'xss_protection' => 'XSS Protection',
                                        'bot_detection' => 'Bot Detection',
                                        'file_validation' => 'File Upload Validation',
                                    ]; ?>
                                    
                                    <?php foreach ($features as $key => $label): ?>
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-gray-600"><?php echo $label; ?></span>
                                            <?php if ($this->settings[$key] ?? false): ?>
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render IP management page
     */
    public function renderIpManagementPage(): void
    {
        $banList = $this->getBanList();
        $whiteList = $this->getWhiteList();
        
        ?>
        <div class="wrap cf7fc-ip-management">
            <div class="bg-white shadow rounded-lg">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">IP Address Management</h1>
                            <p class="text-gray-600 mt-1">Manage banned and whitelisted IP addresses</p>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="px-6 pt-4">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8">
                            <button onclick="showTab('banned')" id="tab-banned" class="tab-button py-4 px-1 border-b-2 border-blue-500 font-medium text-sm text-blue-600">
                                Banned IPs (<?php echo count($banList); ?>)
                            </button>
                            <button onclick="showTab('whitelist')" id="tab-whitelist" class="tab-button py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                Whitelisted IPs (<?php echo count($whiteList); ?>)
                            </button>
                            <button onclick="showTab('manual')" id="tab-manual" class="tab-button py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                Manual Ban
                            </button>
                        </nav>
                    </div>
                </div>
                
                <!-- Banned IPs Tab -->
                <div id="tab-content-banned" class="tab-content p-6">
                    <div class="mb-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-800">Banned IP Addresses</h2>
                            <div class="flex space-x-2">
                                <input type="text" 
                                       id="search-banned" 
                                       placeholder="Search IP..." 
                                       class="px-3 py-1 border border-gray-300 rounded-lg text-sm">
                                <button onclick="clearExpiredBans()" 
                                        class="px-3 py-1 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200">
                                    Clear Expired
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Banned At</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expires</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="banned-ips-table">
                                    <?php if (empty($banList)): ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-8 text-center">
                                                <div class="text-gray-400 mb-2">
                                                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                </div>
                                                <p class="text-gray-500">No banned IP addresses found</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($banList as $ip => $ban): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900 font-mono">
                                                        <?php echo esc_html($ip); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="text-sm text-gray-900">
                                                        <?php echo esc_html($ban['reason']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo esc_html(date('Y-m-d H:i', strtotime($ban['banned_at']))); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($ban['is_permanent']): ?>
                                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Permanent</span>
                                                    <?php else: ?>
                                                        <span class="text-sm text-gray-500">
                                                            <?php echo esc_html(date('Y-m-d H:i', strtotime($ban['expires_at']))); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($ban['is_permanent'] || time() < strtotime($ban['expires_at'])): ?>
                                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Active</span>
                                                    <?php else: ?>
                                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">Expired</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <button onclick="unbanIP('<?php echo esc_js($ip); ?>')" 
                                                                class="text-green-600 hover:text-green-900">
                                                            Unban
                                                        </button>
                                                        <?php if (!$ban['is_permanent']): ?>
                                                            <button onclick="makePermanent('<?php echo esc_js($ip); ?>')" 
                                                                    class="text-red-600 hover:text-red-900">
                                                                Make Permanent
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Whitelist Tab -->
                <div id="tab-content-whitelist" class="tab-content hidden p-6">
                    <div class="mb-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-800">Whitelisted IP Addresses</h2>
                            <button onclick="showAddWhiteListModal()" 
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                                Add IP to Whitelist
                            </button>
                        </div>
                    </div>
                    
                    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Added At</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (empty($whiteList)): ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-8 text-center">
                                                <div class="text-gray-400 mb-2">
                                                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                    </svg>
                                                </div>
                                                <p class="text-gray-500">No whitelisted IP addresses found</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($whiteList as $ip => $data): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900 font-mono">
                                                        <?php echo esc_html($ip); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo esc_html(date('Y-m-d H:i', strtotime($data['added_at']))); ?>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="text-sm text-gray-900">
                                                        <?php echo esc_html($data['notes'] ?? 'No notes'); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="removeFromWhiteList('<?php echo esc_js($ip); ?>')" 
                                                            class="text-red-600 hover:text-red-900">
                                                        Remove
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Manual Ban Tab -->
                <div id="tab-content-manual" class="tab-content hidden p-6">
                    <div class="max-w-2xl">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4">Manually Ban IP Address</h2>
                        
                        <div class="bg-white border border-gray-200 rounded-xl p-6">
                            <form id="manual-ban-form">
                                <div class="space-y-4">
                                    <div>
                                        <label for="ban-ip" class="block text-sm font-medium text-gray-700 mb-1">
                                            IP Address *
                                        </label>
                                        <input type="text" 
                                               id="ban-ip" 
                                               name="ip" 
                                               required 
                                               pattern="^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <p class="mt-1 text-sm text-gray-500">Enter a valid IPv4 address (e.g., 192.168.1.1)</p>
                                    </div>
                                    
                                    <div>
                                        <label for="ban-reason" class="block text-sm font-medium text-gray-700 mb-1">
                                            Reason for Ban *
                                        </label>
                                        <input type="text" 
                                               id="ban-reason" 
                                               name="reason" 
                                               required 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    
                                    <div>
                                        <label for="ban-duration" class="block text-sm font-medium text-gray-700 mb-1">
                                            Ban Duration
                                        </label>
                                        <select id="ban-duration" 
                                                name="duration" 
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="3600">1 Hour</option>
                                            <option value="86400">1 Day</option>
                                            <option value="604800">1 Week</option>
                                            <option value="2592000">1 Month</option>
                                            <option value="permanent">Permanent</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label class="flex items-center">
                                            <input type="checkbox" 
                                                   id="ban-notify" 
                                                   name="notify" 
                                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                            <span class="ml-2 text-sm text-gray-700">
                                                Add to attack logs
                                            </span>
                                        </label>
                                    </div>
                                    
                                    <div class="pt-4">
                                        <button type="submit" 
                                                class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                            Ban IP Address
                                        </button>
                                        <button type="button" 
                                                onclick="clearManualBanForm()" 
                                                class="ml-3 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300">
                                            Clear
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Add to Whitelist Modal -->
        <div id="whitelist-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Add IP to Whitelist</h3>
                            <form id="add-whitelist-form">
                                <div class="space-y-4">
                                    <div>
                                        <label for="whitelist-ip" class="block text-sm font-medium text-gray-700 mb-1">
                                            IP Address *
                                        </label>
                                        <input type="text" 
                                               id="whitelist-ip" 
                                               name="ip" 
                                               required 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label for="whitelist-notes" class="block text-sm font-medium text-gray-700 mb-1">
                                            Notes (Optional)
                                        </label>
                                        <textarea id="whitelist-notes" 
                                                  name="notes" 
                                                  rows="3" 
                                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
                                    </div>
                                </div>
                                <div class="mt-6 flex justify-end space-x-3">
                                    <button type="button" 
                                            onclick="hideWhiteListModal()" 
                                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300">
                                        Cancel
                                    </button>
                                    <button type="submit" 
                                            class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                                        Add to Whitelist
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(el => {
                el.classList.add('hidden');
            });
            
            // Show selected tab content
            document.getElementById('tab-content-' + tabName).classList.remove('hidden');
            
            // Update tab styles
            document.querySelectorAll('.tab-button').forEach(el => {
                el.classList.remove('border-blue-500', 'text-blue-600');
                el.classList.add('border-transparent', 'text-gray-500');
            });
            
            document.getElementById('tab-' + tabName).classList.add('border-blue-500', 'text-blue-600');
            document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');
        }
        
        function showAddWhiteListModal() {
            document.getElementById('whitelist-modal').classList.remove('hidden');
        }
        
        function hideWhiteListModal() {
            document.getElementById('whitelist-modal').classList.add('hidden');
        }
        
        function unbanIP(ip) {
            if (confirm('Are you sure you want to unban ' + ip + '?')) {
                jQuery.post(ajaxurl, {
                    action: 'cf7fc_unban_ip',
                    ip: ip,
                    nonce: '<?php echo wp_create_nonce("cf7fc_unban_ip"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
        }
        
        function makePermanent(ip) {
            if (confirm('Make ban for ' + ip + ' permanent? This cannot be undone automatically.')) {
                jQuery.post(ajaxurl, {
                    action: 'cf7fc_make_permanent',
                    ip: ip,
                    nonce: '<?php echo wp_create_nonce("cf7fc_make_permanent"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
        }
        
        function clearExpiredBans() {
            if (confirm('Clear all expired bans?')) {
                jQuery.post(ajaxurl, {
                    action: 'cf7fc_clear_expired_bans',
                    nonce: '<?php echo wp_create_nonce("cf7fc_clear_expired_bans"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
        }
        
        function removeFromWhiteList(ip) {
            if (confirm('Remove ' + ip + ' from whitelist?')) {
                jQuery.post(ajaxurl, {
                    action: 'cf7fc_remove_whitelist',
                    ip: ip,
                    nonce: '<?php echo wp_create_nonce("cf7fc_remove_whitelist"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Manual ban form
            document.getElementById('manual-ban-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                jQuery.post(ajaxurl, {
                    action: 'cf7fc_manual_ban',
                    ip: formData.get('ip'),
                    reason: formData.get('reason'),
                    duration: formData.get('duration'),
                    notify: formData.get('notify') ? 1 : 0,
                    nonce: '<?php echo wp_create_nonce("cf7fc_manual_ban"); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('IP address banned successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            // Whitelist form
            document.getElementById('add-whitelist-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                jQuery.post(ajaxurl, {
                    action: 'cf7fc_add_whitelist',
                    ip: formData.get('ip'),
                    notes: formData.get('notes'),
                    nonce: '<?php echo wp_create_nonce("cf7fc_add_whitelist"); ?>'
                }, function(response) {
                    if (response.success) {
                        hideWhiteListModal();
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function renderSettingsPage(): void
    {
        $selectedLanguage = $this->settings['language_validation'] ?? 'russian';
        $isRussianEnabled = $this->settings['russian_validation'] ?? true;
        
        ?>
        <div class="wrap cf7fc-settings">
            <div class="bg-white shadow rounded-lg">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Plugin Settings</h1>
                            <p class="text-gray-600 mt-1">Configure security settings for Contact Form 7</p>
                        </div>
                        <button onclick="saveSettings()" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                            Save Settings
                        </button>
                    </div>
                </div>
                
                <!-- Settings Form -->
                <div class="p-6">
                    <form id="settings-form">
                        <!-- Language Validation Section -->
                        <div class="mb-8">
                            <h2 class="text-lg font-semibold text-gray-800 mb-4">Language Validation</h2>
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                                <div class="space-y-4">
                                    <div class="flex items-center">
                                        <input type="checkbox" 
                                               id="enable-language-validation" 
                                               name="language_validation_enabled" 
                                               <?php echo $this->settings['language_validation_enabled'] ? 'checked' : ''; ?> 
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <label for="enable-language-validation" class="ml-2 text-sm font-medium text-gray-700">
                                            Enable language validation for name fields
                                        </label>
                                    </div>
                                    
                                    <div id="language-settings" class="<?php echo $this->settings['language_validation_enabled'] ? '' : 'hidden'; ?> space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                                Select Language to Validate
                                            </label>
                                            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                                <?php foreach (self::LANGUAGES as $code => $lang): ?>
                                                    <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                                        <input type="radio" 
                                                               name="language_validation" 
                                                               value="<?php echo esc_attr($code); ?>"
                                                               <?php echo $selectedLanguage === $code ? 'checked' : ''; ?>
                                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300">
                                                        <span class="ml-2 text-sm text-gray-700"><?php echo esc_html($lang['name']); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <input type="checkbox" 
                                                   id="russian-validation" 
                                                   name="russian_validation" 
                                                   <?php echo $isRussianEnabled ? 'checked' : ''; ?> 
                                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                            <label for="russian-validation" class="ml-2 text-sm font-medium text-gray-700">
                                                Enable Russian character validation (extra check)
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Features Section -->
                        <div class="mb-8">
                            <h2 class="text-lg font-semibold text-gray-800 mb-4">Security Features</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Left Column -->
                                <div class="space-y-4">
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-medium text-gray-700">SQL Injection Protection</h3>
                                                <p class="text-xs text-gray-500 mt-1">Block SQL injection attempts</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" 
                                                       name="sql_injection" 
                                                       <?php echo $this->settings['sql_injection'] ? 'checked' : ''; ?> 
                                                       class="sr-only peer">
                                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-medium text-gray-700">XSS Protection</h3>
                                                <p class="text-xs text-gray-500 mt-1">Block Cross-Site Scripting attacks</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" 
                                                       name="xss_protection" 
                                                       <?php echo $this->settings['xss_protection'] ? 'checked' : ''; ?> 
                                                       class="sr-only peer">
                                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-medium text-gray-700">CSRF Protection</h3>
                                                <p class="text-xs text-gray-500 mt-1">Enable nonce validation</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" 
                                                       name="csrf_protection" 
                                                       <?php echo $this->settings['csrf_protection'] ? 'checked' : ''; ?> 
                                                       class="sr-only peer">
                                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Right Column -->
                                <div class="space-y-4">
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-medium text-gray-700">Rate Limiting</h3>
                                                <p class="text-xs text-gray-500 mt-1">Prevent brute force attacks</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" 
                                                       name="rate_limiting" 
                                                       <?php echo $this->settings['rate_limiting'] ? 'checked' : ''; ?> 
                                                       class="sr-only peer">
                                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-medium text-gray-700">Bot Detection</h3>
                                                <p class="text-xs text-gray-500 mt-1">Detect and block bots</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" 
                                                       name="bot_detection" 
                                                       <?php echo $this->settings['bot_detection'] ? 'checked' : ''; ?> 
                                                       class="sr-only peer">
                                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-medium text-gray-700">File Upload Validation</h3>
                                                <p class="text-xs text-gray-500 mt-1">Validate uploaded files</p>
                                            </div>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input type="checkbox" 
                                                       name="file_validation" 
                                                       <?php echo $this->settings['file_validation'] ? 'checked' : ''; ?> 
                                                       class="sr-only peer">
                                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Rate Limiting Settings -->
                        <div class="mb-8">
                            <h2 class="text-lg font-semibold text-gray-800 mb-4">Rate Limiting Settings</h2>
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Max Requests per Minute
                                        </label>
                                        <input type="number" 
                                               name="max_requests_per_minute" 
                                               value="<?php echo esc_attr($this->settings['max_requests_per_minute'] ?? self::MAX_REQUESTS_PER_MINUTE); ?>"
                                               min="1" max="100" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Ban Threshold
                                        </label>
                                        <input type="number" 
                                               name="ban_threshold" 
                                               value="<?php echo esc_attr($this->settings['ban_threshold'] ?? self::BAN_THRESHOLD); ?>"
                                               min="1" max="1000" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                        <p class="text-xs text-gray-500 mt-1">Number of attacks before permanent ban</p>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Default Ban Duration (hours)
                                        </label>
                                        <input type="number" 
                                               name="ban_duration" 
                                               value="<?php echo esc_attr(($this->settings['ban_duration'] ?? self::BAN_DURATION) / 3600); ?>"
                                               min="1" max="8760" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Logging Settings -->
                        <div class="mb-8">
                            <h2 class="text-lg font-semibold text-gray-800 mb-4">Logging Settings</h2>
                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                                <div class="space-y-4">
                                    <div class="flex items-center">
                                        <input type="checkbox" 
                                               id="enable-logging" 
                                               name="enable_logging" 
                                               <?php echo $this->settings['enable_logging'] ? 'checked' : ''; ?> 
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <label for="enable-logging" class="ml-2 text-sm font-medium text-gray-700">
                                            Enable security logging
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <input type="checkbox" 
                                               id="log_successful_submissions" 
                                               name="log_successful_submissions" 
                                               <?php echo $this->settings['log_successful_submissions'] ? 'checked' : ''; ?> 
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <label for="log_successful_submissions" class="ml-2 text-sm font-medium text-gray-700">
                                            Log successful form submissions
                                        </label>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Log Retention (days)
                                        </label>
                                        <input type="number" 
                                               name="log_retention_days" 
                                               value="<?php echo esc_attr($this->settings['log_retention_days'] ?? 30); ?>"
                                               min="1" max="365" 
                                               class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-lg">
                                        <p class="text-xs text-gray-500 mt-1">Automatically delete logs older than specified days</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle language settings
            const langCheckbox = document.getElementById('enable-language-validation');
            const langSettings = document.getElementById('language-settings');
            
            langCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    langSettings.classList.remove('hidden');
                } else {
                    langSettings.classList.add('hidden');
                }
            });
        });
        
        function saveSettings() {
            const formData = new FormData(document.getElementById('settings-form'));
            const settings = {};
            
            formData.forEach((value, key) => {
                if (key.includes('_')) {
                    settings[key] = value === 'on' ? true : value;
                }
            });
            
            jQuery.post(ajaxurl, {
                action: 'cf7fc_save_settings',
                settings: settings,
                nonce: '<?php echo wp_create_nonce("cf7fc_save_settings"); ?>'
            }, function(response) {
                if (response.success) {
                    alert('Settings saved successfully');
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Get security report
     */
    private function getSecurityReport(): array
    {
        $logs = $this->readSecurityLogs();
        $banList = $this->getBanList();
        
        $report = [
            'total_events' => count($logs),
            'events_last_24h' => 0,
            'banned_ips' => $banList,
            'attack_types' => [],
            'protected_forms' => 0,
            'is_active' => $this->isContactForm7Active(),
        ];
        
        $oneDayAgo = time() - 86400;
        
        foreach ($logs as $log) {
            $timestamp = strtotime($log['timestamp']);
            $eventType = $log['event_type'];
            
            if ($timestamp > $oneDayAgo) {
                $report['events_last_24h']++;
            }
            
            if (!isset($report['attack_types'][$eventType])) {
                $report['attack_types'][$eventType] = 0;
            }
            $report['attack_types'][$eventType]++;
        }
        
        return $report;
    }
    
    /**
     * Get recent bans
     */
    private function getRecentBans(int $limit = 5): array
    {
        $banList = $this->getBanList();
        $recent = [];
        
        foreach ($banList as $ip => $ban) {
            $recent[$ip] = array_merge(['ip' => $ip], $ban);
        }
        
        // Sort by banned_at descending
        usort($recent, function($a, $b) {
            return strtotime($b['banned_at']) - strtotime($a['banned_at']);
        });
        
        return array_slice($recent, 0, $limit);
    }
    
    /**
     * Get ban list
     */
    private function getBanList(): array
    {
        $banFile = CF7FC_LOG_DIR . self::BAN_LIST_FILE;
        
        if (!file_exists($banFile)) {
            return [];
        }
        
        $content = file_get_contents($banFile);
        $banList = json_decode($content, true) ?? [];
        
        // Remove expired bans
        $currentTime = time();
        $updated = false;
        
        foreach ($banList as $ip => $ban) {
            if (!$ban['is_permanent'] && $currentTime > strtotime($ban['expires_at'])) {
                unset($banList[$ip]);
                $updated = true;
            }
        }
        
        if ($updated) {
            $this->saveBanList($banList);
        }
        
        return $banList;
    }
    
    /**
     * Get whitelist
     */
    private function getWhiteList(): array
    {
        $whiteFile = CF7FC_LOG_DIR . self::WHITE_LIST_FILE;
        
        if (!file_exists($whiteFile)) {
            return [];
        }
        
        $content = file_get_contents($whiteFile);
        return json_decode($content, true) ?? [];
    }
    
    /**
     * Save ban list
     */
    private function saveBanList(array $banList): void
    {
        $banFile = CF7FC_LOG_DIR . self::BAN_LIST_FILE;
        file_put_contents($banFile, json_encode($banList, JSON_PRETTY_PRINT));
    }
    
    /**
     * Read security logs
     */
    private function readSecurityLogs(): array
    {
        $logFile = CF7FC_LOG_DIR . self::ATTACK_LOG_FILE;
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $content = file_get_contents($logFile);
        return json_decode($content, true) ?? [];
    }
    
    /**
     * Load settings
     */
    private function loadSettings(): void
    {
        $defaults = [
            'language_validation_enabled' => true,
            'language_validation' => 'russian',
            'russian_validation' => true,
            'sql_injection' => true,
            'xss_protection' => true,
            'csrf_protection' => true,
            'rate_limiting' => true,
            'bot_detection' => true,
            'file_validation' => true,
            'enable_logging' => true,
            'log_successful_submissions' => false,
            'log_retention_days' => 30,
            'max_requests_per_minute' => self::MAX_REQUESTS_PER_MINUTE,
            'ban_threshold' => self::BAN_THRESHOLD,
            'ban_duration' => self::BAN_DURATION,
        ];
        
        $settingsFile = CF7FC_LOG_DIR . self::SETTINGS_FILE;
        
        if (file_exists($settingsFile)) {
            $content = file_get_contents($settingsFile);
            $saved = json_decode($content, true) ?? [];
            $this->settings = array_merge($defaults, $saved);
        } else {
            $this->settings = $defaults;
        }
    }
    
    /**
     * Save settings
     */
    private function saveSettings(array $settings): void
    {
        $this->settings = array_merge($this->settings, $settings);
        $settingsFile = CF7FC_LOG_DIR . self::SETTINGS_FILE;
        file_put_contents($settingsFile, json_encode($this->settings, JSON_PRETTY_PRINT));
    }
    
    /**
     * Check if Contact Form 7 is active
     */
    private function isContactForm7Active(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }
    
    /**
     * Get client IP
     */
    private function getClientIP(): string
    {
        $ipSources = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ipSources as $source) {
            if (!empty($_SERVER[$source])) {
                $ipList = explode(',', $_SERVER[$source]);
                $ip = trim(end($ipList));
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Register hooks
     */
    private function registerHooks(): void
    {
        // Validation hooks
        add_filter('wpcf7_validate_text', [$this, 'validateField'], 10, 2);
        add_filter('wpcf7_validate_text*', [$this, 'validateField'], 10, 2);
        add_filter('wpcf7_validate_email', [$this, 'validateField'], 10, 2);
        add_filter('wpcf7_validate_email*', [$this, 'validateField'], 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_cf7fc_save_settings', [$this, 'ajaxSaveSettings']);
        add_action('wp_ajax_cf7fc_unban_ip', [$this, 'ajaxUnbanIp']);
        add_action('wp_ajax_cf7fc_make_permanent', [$this, 'ajaxMakePermanent']);
        add_action('wp_ajax_cf7fc_clear_expired_bans', [$this, 'ajaxClearExpiredBans']);
        add_action('wp_ajax_cf7fc_manual_ban', [$this, 'ajaxManualBan']);
        add_action('wp_ajax_cf7fc_add_whitelist', [$this, 'ajaxAddWhitelist']);
        add_action('wp_ajax_cf7fc_remove_whitelist', [$this, 'ajaxRemoveWhitelist']);
    }
    
    /**
     * Validate field
     */
    public function validateField(WPCF7_Validation $result, WPCF7_FormTag $tag): WPCF7_Validation
    {
        // Implementation similar to previous versions
        // ... (truncated for brevity)
        
        return $result;
    }
    
    /**
     * AJAX: Save settings
     */
    public function ajaxSaveSettings(): void
    {
        check_ajax_referer('cf7fc_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $settings = $_POST['settings'] ?? [];
        $this->saveSettings($settings);
        
        wp_send_json_success('Settings saved');
    }
    
    /**
     * AJAX: Unban IP
     */
    public function ajaxUnbanIp(): void
    {
        check_ajax_referer('cf7fc_unban_ip', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip'] ?? '');
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        $banList = $this->getBanList();
        
        if (isset($banList[$ip])) {
            unset($banList[$ip]);
            $this->saveBanList($banList);
            wp_send_json_success('IP unbanned');
        } else {
            wp_send_json_error('IP not found in ban list');
        }
    }
    
    /**
     * AJAX: Make ban permanent
     */
    public function ajaxMakePermanent(): void
    {
        check_ajax_referer('cf7fc_make_permanent', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip'] ?? '');
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        $banList = $this->getBanList();
        
        if (isset($banList[$ip])) {
            $banList[$ip]['is_permanent'] = true;
            $banList[$ip]['expires_at'] = date('c', strtotime('+100 years'));
            $this->saveBanList($banList);
            wp_send_json_success('Ban made permanent');
        } else {
            wp_send_json_error('IP not found in ban list');
        }
    }
    
    /**
     * AJAX: Clear expired bans
     */
    public function ajaxClearExpiredBans(): void
    {
        check_ajax_referer('cf7fc_clear_expired_bans', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $banList = $this->getBanList(); // This already removes expired
        $this->saveBanList($banList);
        
        wp_send_json_success('Expired bans cleared');
    }
    
    /**
     * AJAX: Manual ban
     */
    public function ajaxManualBan(): void
    {
        check_ajax_referer('cf7fc_manual_ban', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? 'Manual ban');
        $duration = $_POST['duration'] ?? '3600';
        $notify = (bool) ($_POST['notify'] ?? false);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        $banList = $this->getBanList();
        
        if ($duration === 'permanent') {
            $expiresAt = date('c', strtotime('+100 years'));
            $isPermanent = true;
        } else {
            $expiresAt = date('c', time() + intval($duration));
            $isPermanent = false;
        }
        
        $banList[$ip] = [
            'banned_at' => date('c'),
            'expires_at' => $expiresAt,
            'reason' => $reason,
            'is_permanent' => $isPermanent,
            'attack_type' => 'MANUAL_BAN',
            'banned_by' => get_current_user_id(),
        ];
        
        $this->saveBanList($banList);
        
        if ($notify) {
            $this->logSecurityEvent('MANUAL_BAN', [
                'ip' => $ip,
                'reason' => $reason,
                'duration' => $duration,
                'permanent' => $isPermanent,
            ]);
        }
        
        wp_send_json_success('IP banned successfully');
    }
    
    /**
     * AJAX: Add to whitelist
     */
    public function ajaxAddWhitelist(): void
    {
        check_ajax_referer('cf7fc_add_whitelist', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip'] ?? '');
        $notes = sanitize_text_field($_POST['notes'] ?? '');
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        $whiteList = $this->getWhiteList();
        $whiteList[$ip] = [
            'added_at' => date('c'),
            'notes' => $notes,
            'added_by' => get_current_user_id(),
        ];
        
        $whiteFile = CF7FC_LOG_DIR . self::WHITE_LIST_FILE;
        file_put_contents($whiteFile, json_encode($whiteList, JSON_PRETTY_PRINT));
        
        wp_send_json_success('IP added to whitelist');
    }
    
    /**
     * AJAX: Remove from whitelist
     */
    public function ajaxRemoveWhitelist(): void
    {
        check_ajax_referer('cf7fc_remove_whitelist', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip'] ?? '');
        
        $whiteList = $this->getWhiteList();
        
        if (isset($whiteList[$ip])) {
            unset($whiteList[$ip]);
            $whiteFile = CF7FC_LOG_DIR . self::WHITE_LIST_FILE;
            file_put_contents($whiteFile, json_encode($whiteList, JSON_PRETTY_PRINT));
            wp_send_json_success('IP removed from whitelist');
        } else {
            wp_send_json_error('IP not found in whitelist');
        }
    }
    
    /**
     * Log security event
     */
    private function logSecurityEvent(string $eventType, array $data = []): void
    {
        if (!$this->settings['enable_logging'] ?? true) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('c'),
            'event_type' => $eventType,
            'ip' => $this->clientIP,
            'user_agent' => substr($this->userAgent, 0, 200),
            'data' => $data
        ];
        
        $logs = $this->readSecurityLogs();
        $logs[] = $logEntry;
        
        // Apply retention policy
        $retentionDays = $this->settings['log_retention_days'] ?? 30;
        $cutoffTime = time() - ($retentionDays * 86400);
        
        $logs = array_filter($logs, function($log) use ($cutoffTime) {
            return strtotime($log['timestamp']) > $cutoffTime;
        });
        
        $logFile = CF7FC_LOG_DIR . self::ATTACK_LOG_FILE;
        file_put_contents($logFile, json_encode(array_values($logs), JSON_PRETTY_PRINT));
    }
}

// Initialize plugin
new CF7_Advanced_Security();