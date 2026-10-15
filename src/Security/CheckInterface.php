<?php
/**
 * Contract for all pipeline checks.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security;

defined('ABSPATH') || exit;

interface CheckInterface
{
    /** Stable machine identifier, also the settings key under `checks.*`. */
    public function id(): string;

    /** Human-readable label (translated) for admin UI / registry. */
    public function label(): string;

    /** Whether the check wants to run for this submission. */
    public function enabled(SubmissionContext $context): bool;

    /** Run the check. Implementations MUST NOT throw for expected failures. */
    public function run(SubmissionContext $context): CheckResult;
}
