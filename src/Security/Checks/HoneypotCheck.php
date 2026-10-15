<?php
/**
 * Honeypot field check.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;

defined('ABSPATH') || exit;

/**
 * A per-form hidden field that must stay empty. Most commodity spam bots
 * fill every input they discover in the DOM.
 */
final class HoneypotCheck implements CheckInterface
{
    public function id(): string
    {
        return 'honeypot';
    }

    public function label(): string
    {
        return __('Honeypot', 'spam-checker-for-contact-form-7');
    }

    /**
     * Per-form honeypot field name.
     *
     * Salted with the installation key (wp_salt) so the name cannot be
     * predicted across sites — unlike the pre-4.1.1 plain md5(form_id)
     * scheme, which let an attacker who knows the scheme skip the field.
     */
    public static function fieldName(int $formId): string
    {
        return 'cf7sec_hp_' . substr(hash_hmac('sha256', 'hp:' . $formId, wp_salt('auth')), 0, 12);
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('honeypot');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $field = self::fieldName($context->formId);
        $value = $context->postedData[$field] ?? '';

        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }

        if (trim((string) $value) !== '') {
            return CheckResult::fail(
                $this->id(),
                sprintf(__('Hidden honeypot field "%s" was filled.', 'spam-checker-for-contact-form-7'), $field),
                'honeypot',
                ['field' => $field, 'value' => mb_substr((string) $value, 0, 120)]
            );
        }

        return array_key_exists($field, $context->postedData)
            ? CheckResult::pass($this->id(), ['field' => $field])
            : CheckResult::skip($this->id(), __('Honeypot field missing from submission.', 'spam-checker-for-contact-form-7'));
    }
}
