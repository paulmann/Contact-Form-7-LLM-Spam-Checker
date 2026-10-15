<?php
/**
 * Spam-keyword detector (admin-editable list).
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
 * The keyword mechanism only: matching and list normalisation live in pure
 * static functions so they can be tested without WordPress.
 *
 * The pattern bank itself (docs/spam-patterns.md) is maintained by the project
 * owner in a separate process — this class never ships a pattern list of its
 * own, and `Settings::DEFAULTS` stays the only source of a default list.
 */
final class KeywordSpamCheck implements CheckInterface
{
    /**
     * Keywords shorter than this are dropped by {@see parseList()}: one or two
     * letters inside a substring match almost always cause false positives.
     */
    public const MIN_KEYWORD_LENGTH = 4;

    public function id(): string
    {
        return 'keyword_spam';
    }

    public function label(): string
    {
        return __('Keyword blacklist', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('keyword_spam');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $keywords = self::parseList((string) $context->settings->get('checks.keyword_spam.keywords', ''));
        if ($keywords === []) {
            return CheckResult::skip($this->id(), __('Keyword list is empty.', 'spam-checker-for-contact-form-7'));
        }

        foreach ($context->textFields() as $field => $value) {
            $hits = self::matches(mb_substr($value, 0, 20000), $keywords);
            if ($hits === []) {
                continue;
            }

            // Unchanged behaviour: the first matching keyword stops the check.
            return CheckResult::fail(
                $this->id(),
                sprintf(
                    __('Blacklisted keyword "%1$s" found in field "%2$s".', 'spam-checker-for-contact-form-7'),
                    $hits[0],
                    $field
                ),
                'keyword_spam',
                [
                    'field'   => $field,
                    'keyword' => $hits[0],
                    'sample'  => mb_substr($value, 0, 160),
                ]
            );
        }

        return CheckResult::pass($this->id(), ['keywords' => count($keywords)]);
    }

    /**
     * Case-insensitive substring matching of every keyword in $keywords.
     *
     * Multi-word phrases work because plain substring containment is used.
     * Keywords shorter than $minLength are ignored, so the function is safe to
     * call with an unparsed list as well.
     *
     * @param string             $haystack Text to search (any case).
     * @param array<int, string> $keywords Keywords, e.g. from {@see parseList()}.
     *
     * @return array<int, string> Matched keywords in list order, original spelling.
     */
    public static function matches(string $haystack, array $keywords, int $minLength = self::MIN_KEYWORD_LENGTH): array
    {
        $haystack = mb_strtolower($haystack);
        $found    = [];

        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            $needle  = mb_strtolower($keyword);

            if ($needle === '' || mb_strlen($needle) < max(1, $minLength)) {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                $found[] = $keyword;
            }
        }

        return $found;
    }

    /**
     * Split a newline/comma-separated admin list into normalised keywords:
     * trimmed, empties and duplicates dropped, tokens shorter than
     * $minLength ignored. Duplicates are compared case-insensitively and the
     * first spelling wins.
     *
     * @return array<int, string>
     */
    public static function parseList(string $raw, int $minLength = self::MIN_KEYWORD_LENGTH): array
    {
        $parts    = preg_split('/[\r\n,]+/', $raw) ?: [];
        $minLength = max(1, $minLength);
        $unique   = [];

        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '' || mb_strlen($token) < $minLength) {
                continue;
            }
            $unique[mb_strtolower($token)] ??= $token;
        }

        return array_values($unique);
    }
}
