<?php
/**
 * Everything the pipeline knows about one form submission.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security;

use CF7Sec\Settings;

defined('ABSPATH') || exit;

/**
 * Immutable snapshot of a submission + lazily attached geo metadata.
 */
final class SubmissionContext
{
    /** @var array<string, mixed>|null Geo record fetched by the AbuseIPDB check. */
    private ?array $geo = null;

    /** @var array<int, CheckResult> Results collected so far (pipeline-owned). */
    public array $collected = [];

    /**
     * @param array<string, mixed> $postedData Raw posted data (strings/arrays).
     */
    public function __construct(
        public readonly int $formId,
        public readonly string $formTitle,
        public readonly array $postedData,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $referrer,
        public readonly int $serverTime,
        public readonly Settings $settings,
    ) {
    }

    /**
     * Flat "field => value" map of all textual values in the submission.
     * Internal control fields (starting with `_` or `cf7sec_`) are excluded.
     *
     * @return array<string, string>
     */
    public function textFields(): array
    {
        $out = [];
        foreach ($this->postedData as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, '_') || str_starts_with($key, 'cf7sec_')) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $out[$key] = (string) $value;
        }

        return $out;
    }

    /**
     * Field values worth sending to the LLM (excludes control fields and
     * truncates very long values).
     *
     * @return array<string, string>
     */
    public function llmFields(int $maxLen = 4000): array
    {
        $out = [];
        foreach ($this->textFields() as $key => $value) {
            $out[$key] = mb_substr($value, 0, $maxLen);
        }

        return $out;
    }

    /** @param array<string, mixed>|null $geo */
    public function setGeo(?array $geo): void
    {
        $this->geo = $geo;
    }

    /** @return array<string, mixed>|null */
    public function geo(): ?array
    {
        return $this->geo;
    }

    public function country(): string
    {
        return (string) ($this->geo['country_code'] ?? '');
    }
}
