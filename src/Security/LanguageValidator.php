<?php
/**
 * Field-level language validation for CF7 `wpcf7_validate_*` filters.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security;

use CF7Sec\Languages;
use CF7Sec\Services\Logger;
use CF7Sec\Settings;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Support\Ip;
use WPCF7_FormTag;
use WPCF7_Validation;

defined('ABSPATH') || exit;

/**
 * Validates configured name-like fields against the character set of the
 * selected language. Unlike the spam pipeline this produces a friendly
 * per-field validation error instead of a hard "spam" verdict — unless
 * strict mode is enabled, which additionally logs the attempt and adds a
 * strike against the IP.
 */
final class LanguageValidator
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('checks.language.enabled', true);
    }

    /**
     * `wpcf7_validate_text|email|tel|textarea` filter callback.
     */
    public function validate(WPCF7_Validation $result, WPCF7_FormTag $tag): WPCF7_Validation
    {
        if (!$this->enabled() || $tag->name === '') {
            return $result;
        }

        $fields = $this->targetFields();
        if (!in_array(strtolower($tag->name), $fields, true)) {
            return $result;
        }

        $language = (string) $this->settings->get('checks.language.language', Languages::DEFAULT_LANG);
        if (!isset(Languages::REGEX[$language])) {
            return $result;
        }

        $value = $this->postedValue($tag->name);
        if ($value === '') {
            return $result;
        }

        $regex = Languages::REGEX[$language]['regex'];
        if (@preg_match($regex, $value) === 1) {
            $this->logger->debug('LANGUAGE_OK', ['field' => $tag->name, 'language' => $language]);

            return $result;
        }

        $message = $this->errorMessage($tag->name, $language);
        $result->invalidate($tag, $message);

        $strict = (bool) $this->settings->get('checks.language.strict', false);

        EventsRepository::record([
            'event_type' => 'language_invalid',
            'verdict'    => $strict ? EventsRepository::VERDICT_BLOCKED : EventsRepository::VERDICT_ALLOWED,
            'ip'         => Ip::clientFromSettings($this->settings),
            'reasons'    => [sprintf('Field "%s" failed %s language validation', $tag->name, $language)],
            'checks'     => [[
                'check'  => 'language',
                'status' => 'fail',
                'details' => [
                    'field'    => $tag->name,
                    'language' => $language,
                    'sample'   => mb_substr($value, 0, 160),
                    'strict'   => $strict,
                ],
            ]],
        ]);

        $this->logger->debug('LANGUAGE_INVALID', ['field' => $tag->name, 'language' => $language, 'strict' => $strict]);

        return $result;
    }

    /**
     * Field value as Contact Form 7 sees it (prepared, unslashed once).
     *
     * Prefers the submission object over $_POST: CF7 normalises arrays,
     * pipes and multi-values there. $_POST is only a last-resort fallback
     * (e.g. validation triggered outside a submission) and handles arrays.
     */
    private function postedValue(string $fieldName): string
    {
        $submission = class_exists('\WPCF7_Submission') ? \WPCF7_Submission::get_instance() : null;

        if ($submission !== null) {
            $posted = $submission->get_posted_data();
            if (isset($posted[$fieldName])) {
                return is_array($posted[$fieldName])
                    ? trim(implode(' ', array_map('strval', $posted[$fieldName])))
                    : trim((string) $posted[$fieldName]);
            }

            return '';
        }

        // Fallback: raw request data (already slashed by WordPress).
        if (!isset($_POST[$fieldName])) {
            return '';
        }
        $raw = wp_unslash($_POST[$fieldName]);

        return is_array($raw) ? trim(implode(' ', array_map('strval', $raw))) : trim((string) $raw);
    }

    /**
     * Lower-cased list of field names subject to language validation.
     *
     * @return array<int, string>
     */
    public function targetFields(): array
    {
        $raw = trim((string) $this->settings->get('checks.language.fields', ''));
        if ($raw === '') {
            $raw = 'name,your-name,first-name,last-name,full-name,family-name,given-name,middle-name,fio';
        }

        return array_values(array_filter(array_map(
            static fn(string $f): string => strtolower(trim($f)),
            explode(',', $raw)
        )));
    }

    public function errorMessage(string $field, string $language): string
    {
        $template = (string) $this->settings->get('checks.language.error_message', '');
        if ($template === '') {
            $template = (string) $this->settings->get('messages.language_validation', Settings::DEFAULTS['messages']['language_validation']);
        }

        return str_replace(
            ['%field_name%', '%language_name%'],
            [$field, Languages::REGEX[$language]['name'] ?? $language],
            $template
        );
    }
}
