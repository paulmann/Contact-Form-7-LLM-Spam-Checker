<?php
/**
 * Signature registries: SQLi, XSS and bot user-agent patterns.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security;

defined('ABSPATH') || exit;

/**
 * Central, self-documented pattern lists. Patterns were tuned to keep
 * false positives low (word boundaries, contextual qualifiers) while
 * covering the vectors seen in the wild against contact forms.
 */
final class Patterns
{
    /**
     * SQL-injection signatures: pattern => human description.
     *
     * @var array<string, string>
     */
    public const SQLI = [
        '/\b(union\s+(all\s+)?select)\b/i'                          => 'UNION SELECT injection',
        '/\b(insert\s+into|delete\s+from|drop\s+(table|database)|alter\s+table|truncate\s+table|create\s+(table|database)|update\s+\w+\s+set)\b/i' => 'SQL statement phrase',
        '/\bselect\b(?:(?!\bfrom\b)[\s\S]){0,120}?[*\'"(;](?:(?!\bfrom\b)[\s\S]){0,120}?\bfrom\b/i' => 'SELECT … FROM with SQL metacharacters',
        '/\b(or|and)\s+[\w"\']+\s*[=<>!]+\s*[\w"\']+|\b(or|and)\s+\d+\s*=\s*\d+/i' => 'Tautology (OR 1=1 style)',
        '/\b(sleep|benchmark|pg_sleep|waitfor\s+delay)\s*\(/i'     => 'Time-based blind SQLi probe',
        '/@@version|\bversion\s*\(\)|\buser\s*\(\)|\bcurrent_user\b\s*\(/i' => 'DB metadata probe',
        '/\b(load_file|into\s+(out|dump)file)\s*\(?/i'              => 'File read/write attempt',
        '/\b(information_schema|pg_catalog|sqlite_master|sysobjects|sys\.tables|mysql\s*\.\s*user)\b/i' => 'System catalog probe',
        '/\bwp_(users|options|posts|postmeta|usermeta|comments|config)\b/i' => 'WordPress table probe',
        '/\b(exec|execute)\s*(\(|\s)\s*(sp_|xp_)/i'                 => 'Stored procedure execution',
        '/(?:^|[\s(])--\s|--$|\/\*[\s\S]*?\*\//m'                  => 'SQL comment sequence',
        '/;\s*(drop|delete|update|insert|alter|create|exec)\b/i'     => 'Stacked query attempt',
        '/\b0x[0-9a-f]{8,}\b/i'                                     => 'Hex-encoded payload',
    ];

    /**
     * XSS / script-injection signatures: pattern => human description.
     *
     * @var array<string, string>
     */
    public const XSS = [
        '/<\s*script[\s>]/i'                                        => 'Inline <script> tag',
        '/<\s*\/\s*script\s*>/i'                                    => 'Closing </script> tag',
        '/javascript\s*:/i'                                         => 'javascript: URI',
        '/vbscript\s*:/i'                                           => 'vbscript: URI',
        '/data\s*:\s*(text\/html|application\/xhtml|image\/svg\+xml)[^,]*;?\s*base64/i' => 'Dangerous data: URI',
        '/<\s*(iframe|object|embed|applet|form|meta|link|base|svg|math)\b/i' => 'Dangerous HTML tag',
        '/\bon(load|error|click|mouse\w+|focus|blur|submit|abort|animate\w*)\s*=/i' => 'Inline event handler',
        '/expression\s*\(/i'                                        => 'CSS expression()',
        '/document\s*\.\s*(cookie|write|location)|window\s*\.\s*location|eval\s*\(|setTimeout\s*\(\s*["\']/i' => 'Scripting construct',
        '/<\s*img\b[^>]*\bonerror\s*=/i'                            => 'IMG onerror payload',
    ];

    /**
     * Bot / scraper tokens matched against the User-Agent header.
     *
     * @var array<int, string>
     */
    public const BOT_AGENTS = [
        // Generic automation
        'bot', 'crawl', 'spider', 'scrape', 'scraper', 'crawler',
        'curl', 'wget', 'python', 'requests', 'urllib', 'httpclient', 'java/', 'php/',
        'ruby', 'perl', 'go-http', 'node', 'axios', 'okhttp', 'libwww', 'winhttp',
        // Security scanners
        'zgrab', 'masscan', 'nmap', 'sqlmap', 'nikto', 'httrack', 'webcopy', 'acunetix',
        'nessus', 'openvas', 'wpscan', 'zaproxy', 'burp', 'dirbuster', 'gobuster',
        // SEO / marketing crawlers
        'semrush', 'ahrefs', 'mj12bot', 'dotbot', 'rogerbot', 'exabot', 'megaindex',
        'sitebulb', 'screaming frog', 'serpstat', 'dataforseo',
        // Headless browsers / test drivers
        'headlesschrome', 'phantomjs', 'selenium', 'playwright', 'puppeteer', 'electron',
        'webdriver', 'htmlunit', 'jsoup',
        // Harvesters
        'harvester', 'extract', 'miner', 'collector', 'scan',
    ];

    /**
     * Tokens that legitimately appear in browser UAs and must NOT alone
     * trigger bot detection (checked against word boundaries).
     *
     * @var array<int, string>
     */
    public const UA_FALSE_POSITIVES = [
        'chrome', 'safari', 'firefox', 'edge', 'opera', 'mobile', 'tablet',
        'android', 'iphone', 'ipad', 'windows', 'macintosh', 'linux',
    ];

    /**
     * Tokens that are *always* strong bot evidence even if they look generic.
     *
     * @var array<int, string>
     */
    public const UA_STRONG_EVIDENCE = [
        'headlesschrome', 'phantomjs', 'selenium', 'playwright', 'puppeteer',
        'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'wpscan', 'curl', 'wget',
        'python-requests', 'go-http-client', 'java/', 'libwww-perl', 'scrapy',
    ];

    /**
     * @return array<string, string> pattern => description, plus user extras.
     */
    public static function sqli(): array
    {
        return self::SQLI;
    }

    /**
     * @return array<string, string>
     */
    public static function xss(): array
    {
        return self::XSS;
    }
}
