<?php
/**
 * SQL-injection signature scanner.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\Patterns;
use CF7Sec\Security\SubmissionContext;

defined('ABSPATH') || exit;

final class SqlInjectionCheck implements CheckInterface
{
    public function id(): string
    {
        return 'sql_injection';
    }

    public function label(): string
    {
        return __('SQL-injection detection', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('sql_injection');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        foreach ($context->textFields() as $field => $value) {
            if ($value === '' || mb_strlen($value) > 20000) {
                $value = mb_substr($value, 0, 20000);
            }

            foreach (Patterns::sqli() as $pattern => $description) {
                if (@preg_match($pattern, $value) === 1) {
                    return CheckResult::fail(
                        $this->id(),
                        sprintf(
                            __('SQL-injection signature "%1$s" matched in field "%2$s".', 'spam-checker-for-contact-form-7'),
                            $description,
                            $field
                        ),
                        'sql_injection',
                        [
                            'field'       => $field,
                            'signature'   => $description,
                            'pattern'     => $pattern,
                            'sample'      => mb_substr($value, 0, 160),
                        ]
                    );
                }
            }
        }

        return CheckResult::pass($this->id());
    }
}
