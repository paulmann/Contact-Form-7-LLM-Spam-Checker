<?php
/**
 * Polyfills for hosts without the mbstring extension.
 *
 * mbstring ships with virtually every WordPress host; these best-effort
 * fallbacks only activate when it is genuinely missing, so the plugin
 * never hard-crashes on a minimal PHP install.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null): int
    {
        $string = (string) $string;
        if (function_exists('iconv_strlen')) {
            $len = @iconv_strlen($string, 'UTF-8');
            if ($len !== false) {
                return $len;
            }
        }

        return (int) preg_match_all('/./us', $string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null): string
    {
        $string = (string) $string;
        $start  = (int) $start;
        $length = $length === null ? null : (int) $length;
        if (function_exists('iconv_substr')) {
            $out = @iconv_substr($string, $start, $length, 'UTF-8');
            if ($out !== false) {
                return $out;
            }
        }

        // Byte-level fallback (may split multibyte characters at the edges).
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null): string
    {
        return strtolower((string) $string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null): string
    {
        return strtoupper((string) $string);
    }
}
