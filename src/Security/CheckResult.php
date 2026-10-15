<?php
/**
 * Result value object produced by every security check.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security;

defined('ABSPATH') || exit;

/**
 * Immutable result of a single check in the pipeline.
 */
final class CheckResult
{
    /**
     * @param array<string, mixed> $details Machine-readable evidence stored in the registry.
     */
    private function __construct(
        public readonly string $checkId,
        public readonly CheckStatus $status,
        public readonly string $reason = '',
        public readonly string $messageKey = '',
        public readonly array $details = [],
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function pass(string $checkId, array $details = []): self
    {
        return new self($checkId, CheckStatus::Pass, details: $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function fail(string $checkId, string $reason, string $messageKey, array $details = []): self
    {
        return new self($checkId, CheckStatus::Fail, $reason, $messageKey, $details);
    }

    public static function skip(string $checkId, string $why = ''): self
    {
        return new self($checkId, CheckStatus::Skip, $why);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $checkId, string $message, array $details = []): self
    {
        return new self($checkId, CheckStatus::Error, $message, details: $details);
    }

    public function isFail(): bool
    {
        return $this->status === CheckStatus::Fail;
    }

    /**
     * Plain-array representation for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check'   => $this->checkId,
            'status'  => $this->status->value,
            'reason'  => $this->reason,
            'details' => $this->details,
        ];
    }
}
