<?php
/**
 * Client IP resolution with proxy/CDN awareness.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Support;

use CF7Sec\Settings;

defined('ABSPATH') || exit;

/**
 * Resolves the *real* client IP behind reverse proxies and CDNs,
 * honouring the administrator-selected header mode.
 *
 * Security note: forwarded headers can be spoofed unless the request
 * actually passes through a trusted proxy. The default `auto` mode only
 * trusts a forwarded header when REMOTE_ADDR itself is a private or
 * loopback address (i.e. a local reverse proxy) or Cloudflare IP ranges.
 */
final class Ip
{
    /** Cloudflare published IPv4 ranges. */
    private const CF_V4 = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    /** Cloudflare published IPv6 ranges. */
    private const CF_V6 = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function client(string $mode = 'auto'): string
    {
        $remote = self::valid($_SERVER['REMOTE_ADDR'] ?? '');

        switch ($mode) {
            case 'remote_only':
                return $remote ?: '0.0.0.0';

            case 'cf':
                return self::firstValid($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') ?: ($remote ?: '0.0.0.0');

            case 'xff':
                return self::firstFromList($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') ?: ($remote ?: '0.0.0.0');

            case 'auto':
            default:
                if ($remote !== '' && (self::isPrivate($remote) || self::isCloudflare($remote))) {
                    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                        $candidate = self::firstFromList($_SERVER[$header] ?? '') ?? '';
                        if ($candidate !== '' && $candidate !== $remote) {
                            return $candidate;
                        }
                    }
                }

                return $remote ?: '0.0.0.0';
        }
    }

    public static function clientFromSettings(Settings $settings): string
    {
        return self::client((string) $settings->get('general.ip_header_mode', 'auto'));
    }

    public static function valid(string $ip): string
    {
        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    public static function firstValid(string $ip): string
    {
        return self::valid($ip);
    }

    /**
     * First valid public IP from a comma-separated header value;
     * falls back to any valid IP.
     */
    public static function firstFromList(string $list): string
    {
        if (trim($list) === '') {
            return '';
        }

        $parts  = array_map('trim', explode(',', $list));
        $backup = '';
        foreach ($parts as $part) {
            if (self::valid($part) !== '') {
                if (!self::isPrivate($part)) {
                    return $part;
                }
                $backup = $backup ?: $part;
            }
        }

        return $backup;
    }

    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function isCloudflare(string $ip): bool
    {
        $ranges = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? self::CF_V4 : self::CF_V6;
        foreach ($ranges as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipBin    = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || $bits === '') {
            return false;
        }

        $bits = (int) $bits;
        $mask = str_repeat("\xff", intdiv($bits, 8));
        $rem  = $bits % 8;
        if ($rem > 0) {
            $mask .= chr(0xff << (8 - $rem) & 0xff);
        }

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * Stable hash used for transient keys (avoids storing raw IPs in option names).
     */
    public static function hash(string $ip): string
    {
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    /**
     * Partially mask an IP for privacy-conscious display: 1.2.x.x / 2001:db8::xxxx
     */
    public static function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts    = explode('.', $ip);
            $parts[2] = $parts[3] = 'x';

            return implode('.', $parts);
        }

        return preg_replace('/[0-9a-f]+$/i', 'xxxx', $ip) ?? $ip;
    }
}
