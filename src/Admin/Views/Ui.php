<?php
/**
 * Small UI component helpers — the "shadcn-style" primitives rendered
 * server-side with Tailwind utility classes.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Admin\Views;

defined('ABSPATH') || exit;

/**
 * Every input carries a dotted `name` (settings path). The admin JS
 * serialises the whole page form into a nested object and POSTs it to
 * the REST API — no per-field AJAX plumbing needed.
 */
final class Ui
{
    /**
     * Card container.
     */
    public static function card(string $title, string $description, string $body, string $footer = '', string $extraClass = ''): string
    {
        $html = '<div class="c-card ' . esc_attr($extraClass) . '">';
        if ($title !== '') {
            $html .= '<div class="c-card-header">';
            $html .= '<h3 class="c-card-title">' . esc_html($title) . '</h3>';
            if ($description !== '') {
                $html .= '<p class="c-card-desc">' . esc_html($description) . '</p>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="c-card-body">' . $body . '</div>';
        if ($footer !== '') {
            $html .= '<div class="c-card-footer">' . $footer . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Accessible switch (styled checkbox).
     */
    public static function switch(string $name, bool $checked, string $label, string $hint = ''): string
    {
        $id = 'sw-' . preg_replace('/[^a-z0-9]+/i', '-', $name);

        return '<label class="c-switch-row" for="' . esc_attr($id) . '">'
            . '<span class="c-switch-text"><span class="c-switch-label">' . esc_html($label) . '</span>'
            . ($hint !== '' ? '<span class="c-switch-hint">' . esc_html($hint) . '</span>' : '')
            . '</span>'
            . '<button type="button" role="switch" aria-checked="' . ($checked ? 'true' : 'false') . '" '
            . 'data-name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="c-switch" tabindex="0">'
            . '<span class="c-switch-thumb"></span></button>'
            . '<input type="hidden" name="' . esc_attr($name) . '" value="' . ($checked ? '1' : '0') . '" data-switch-input>'
            . '</label>';
    }

    /**
     * Plain checkbox (for multi-select groups).
     */
    public static function checkbox(string $name, bool $checked, string $label): string
    {
        $id = 'cb-' . preg_replace('/[^a-z0-9]+/i', '-', $name . $label);

        return '<label class="c-checkbox-row" for="' . esc_attr($id) . '">'
            . '<input type="checkbox" class="c-checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . ($checked ? 'checked' : '') . '>'
            . '<span>' . esc_html($label) . '</span></label>';
    }

    /**
     * Labelled text input row.
     *
     * @param array<string, string|int> $attr
     */
    public static function text(string $name, string $value, string $label, string $hint = '', array $attr = []): string
    {
        return self::field($label, $hint, '<input type="text" class="c-input" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . self::attrs($attr) . '>');
    }

    /**
     * @param array<string, string|int> $attr
     */
    public static function number(string $name, int|float $value, string $label, string $hint = '', array $attr = []): string
    {
        return self::field($label, $hint, '<input type="number" class="c-input" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . self::attrs($attr) . '>');
    }

    /**
     * Secret input with reveal toggle. The stored value is never printed;
     * a mask placeholder is shown instead.
     */
    public static function secret(string $name, string $masked, string $label, string $hint = ''): string
    {
        $id = 'sec-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
        $input = '<div class="c-secret-wrap">'
            . '<input type="password" class="c-input c-secret" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" '
            . 'value="' . esc_attr($masked) . '" placeholder="' . esc_attr__('Paste new key…', 'contact-form-7-spam-checker') . '" autocomplete="off" spellcheck="false">'
            . '<button type="button" class="c-btn c-btn-ghost c-btn-icon c-secret-toggle" data-target="' . esc_attr($id) . '" '
            . 'aria-label="' . esc_attr__('Show key', 'contact-form-7-spam-checker') . '" title="' . esc_attr__('Show key', 'contact-form-7-spam-checker') . '">'
            . self::icon('eye') . '</button>'
            . '</div>';

        return self::field($label, $hint, $input);
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, array $options, string $value, string $label, string $hint = ''): string
    {
        $html = '<select class="c-select" name="' . esc_attr($name) . '">';
        foreach ($options as $optValue => $optLabel) {
            $html .= '<option value="' . esc_attr((string) $optValue) . '"' . ((string) $optValue === $value ? ' selected' : '') . '>' . esc_html($optLabel) . '</option>';
        }
        $html .= '</select>';

        return self::field($label, $hint, $html);
    }

    /**
     * @param array<string, string|int> $attr
     */
    public static function textarea(string $name, string $value, string $label, string $hint = '', array $attr = []): string
    {
        $defaults = ['rows' => 3];
        $attr     = array_merge($defaults, $attr);

        return self::field($label, $hint, '<textarea class="c-textarea" name="' . esc_attr($name) . '"' . self::attrs($attr) . '>' . esc_textarea($value) . '</textarea>');
    }

    /**
     * Two-column grid row used inside cards.
     */
    public static function grid(string ...$cells): string
    {
        return '<div class="c-grid-2">' . implode('', $cells) . '</div>';
    }

    private static function field(string $label, string $hint, string $control): string
    {
        return '<div class="c-field">'
            . '<span class="c-field-label">' . esc_html($label) . '</span>'
            . $control
            . ($hint !== '' ? '<span class="c-field-hint">' . wp_kses_post($hint) . '</span>' : '')
            . '</div>';
    }

    /**
     * @param array<string, string|int> $attr
     */
    private static function attrs(array $attr): string
    {
        $out = '';
        foreach ($attr as $k => $v) {
            $out .= ' ' . esc_attr((string) $k) . '="' . esc_attr((string) $v) . '"';
        }

        return $out;
    }

    public static function badge(string $text, string $variant = 'outline'): string
    {
        return '<span class="c-badge c-badge-' . esc_attr($variant) . '">' . esc_html($text) . '</span>';
    }

    public static function button(string $text, string $variant = 'default', string $extraAttr = ''): string
    {
        return '<button type="button" class="c-btn c-btn-' . esc_attr($variant) . '" ' . $extraAttr . '>' . esc_html($text) . '</button>';
    }

    /**
     * Inline SVG icon (lucide-style strokes, no external requests).
     */
    public static function icon(string $name, string $class = 'c-icon'): string
    {
        $paths = match ($name) {
            'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
            'moon'     => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
            'monitor'  => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
            'check'    => '<path d="M20 6 9 17l-5-5"/>',
            'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
            'ban'      => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
            'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>',
            'key'      => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
            'bot'      => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2m16 0h2M10 12v2m4-2v2"/>',
            'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'trash'    => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
            'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
            'refresh'  => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
            'eye'      => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off'  => '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24M10.73 5.08A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a13.2 13.2 0 0 1-1.67 2.68M6.61 6.61A13.5 13.5 0 0 0 2 12s3 7 10 7a9.7 9.7 0 0 0 5.39-1.61M2 2l20 20"/>',
            'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'plus'     => '<path d="M5 12h14M12 5v14"/>',
            'alert'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4m0 4h.01"/>',
            'mail'     => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'cpu'      => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2M15 20v2M2 15h2M20 15h2M9 2v2M9 20v2"/>',
            'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'sliders'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
            'zap'      => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
            'default'   => '',
        };

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths ?? '') . '</svg>';
    }
}
