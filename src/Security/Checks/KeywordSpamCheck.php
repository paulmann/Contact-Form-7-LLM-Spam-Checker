<?php
/**
 * Spam-keyword detector (admin-editable list).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;

defined('ABSPATH') || exit;

final class KeywordSpamCheck implements CheckInterface
{
    public function id(): string
    {
        return 'keyword_spam';
    }

    public function label(): string
    {
        return __('Keyword blacklist', 'contact-form-7-spam-checker');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('keyword_spam');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $keywords = self::parseList((string) $context->settings->get('checks.keyword_spam.keywords', ''));
        if ($keywords === []) {
            return CheckResult::skip($this->id(), __('Keyword list is empty.', 'contact-form-7-spam-checker'));
        }

        foreach ($context->textFields() as $field => $value) {
            $haystack = mb_strtolower(mb_substr($value, 0, 20000));

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
                    return CheckResult::fail(
                        $this->id(),
                        sprintf(
                            __('Blacklisted keyword "%1$s" found in field "%2$s".', 'contact-form-7-spam-checker'),
                            $keyword,
                            $field
                        ),
                        'keyword_spam',
                        [
                            'field'   => $field,
                            'keyword' => $keyword,
                            'sample'  => mb_substr($value, 0, 160),
                        ]
                    );
                }
            }
        }

        return CheckResult::pass($this->id(), ['keywords' => count($keywords)]);
    }

    /**
     * Split a newline/comma-separated admin list into keywords.
     *
     * @return array<int, string>
     */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn(string $p): string => trim($p),
            $parts
        ), static fn(string $p): bool => $p !== ''));
    }
}
