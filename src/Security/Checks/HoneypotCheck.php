<?php
/**
 * Honeypot field check.
 *
 * @package contact-form-7-spam-checker
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
        return __('Honeypot', 'contact-form-7-spam-checker');
    }

    public static function fieldName(int $formId): string
    {
        return 'cf7sec_hp_' . substr(md5((string) $formId), 0, 8);
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
                sprintf(__('Hidden honeypot field "%s" was filled.', 'contact-form-7-spam-checker'), $field),
                'honeypot',
                ['field' => $field, 'value' => mb_substr((string) $value, 0, 120)]
            );
        }

        return array_key_exists($field, $context->postedData)
            ? CheckResult::pass($this->id(), ['field' => $field])
            : CheckResult::skip($this->id(), __('Honeypot field missing from submission.', 'contact-form-7-spam-checker'));
    }
}
