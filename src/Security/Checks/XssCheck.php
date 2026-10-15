<?php
/**
 * XSS / script-injection signature scanner.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\Patterns;
use CF7Sec\Security\SubmissionContext;

defined('ABSPATH') || exit;

final class XssCheck implements CheckInterface
{
    public function id(): string
    {
        return 'xss';
    }

    public function label(): string
    {
        return __('XSS detection', 'contact-form-7-spam-checker');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('xss');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        foreach ($context->textFields() as $field => $value) {
            $value = mb_substr($value, 0, 20000);
            // Normalise encoded variants before scanning.
            $decoded = $this->decode($value);

            foreach (Patterns::xss() as $pattern => $description) {
                if (@preg_match($pattern, $value) === 1 || @preg_match($pattern, $decoded) === 1) {
                    return CheckResult::fail(
                        $this->id(),
                        sprintf(
                            __('XSS signature "%1$s" matched in field "%2$s".', 'contact-form-7-spam-checker'),
                            $description,
                            $field
                        ),
                        'xss_attack',
                        [
                            'field'     => $field,
                            'signature' => $description,
                            'pattern'   => $pattern,
                            'sample'    => mb_substr($value, 0, 160),
                        ]
                    );
                }
            }
        }

        return CheckResult::pass($this->id());
    }

    /**
     * Best-effort decoding of HTML entities and URL encoding so obfuscated
     * payloads (`%3Cscript%3E`, `&lt;script&gt;`) are still caught.
     */
    private function decode(string $value): string
    {
        $out = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = rawurldecode($out);

        return $raw . "\n" . $out;
    }
}
