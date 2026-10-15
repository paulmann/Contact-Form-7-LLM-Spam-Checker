<?php
/**
 * Time-based plausibility check ("form filled too fast / stale page").
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
 * Uses two independent signals:
 *  1. `cf7sec_age` — seconds between page load and submit, measured by JS
 *     with a monotonic client timer (immune to clock skew and page caches).
 *  2. `cf7sec_ts` — server render time, used as a fallback with a
 *     configurable clock-skew tolerance.
 *
 * Field names must NOT start with an underscore: Contact Form 7 strips
 * `_`-prefixed keys from `get_posted_data()`.
 */
final class TimeCheck implements CheckInterface
{
    public const FIELD_AGE = 'cf7sec_age';
    public const FIELD_TS  = 'cf7sec_ts';

    public function id(): string
    {
        return 'time_check';
    }

    public function label(): string
    {
        return __('Timing analysis', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('time_check');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $min    = max(0, (int) $context->settings->get('checks.time_check.min_seconds', 3));
        $maxAge = max(60, (int) $context->settings->get('checks.time_check.max_age_seconds', 3600));
        $skew   = max(0, (int) $context->settings->get('checks.time_check.skew_tolerance', 300));

        // Signal 1: JS-measured dwell time (preferred).
        $age = $context->postedData[self::FIELD_AGE] ?? null;
        if ($age !== null && is_numeric($age)) {
            $age = (int) $age;
            if ($age >= 0 && $age < $min) {
                return CheckResult::fail(
                    $this->id(),
                    sprintf(__('Submitted in %d s — faster than the %d s human minimum.', 'spam-checker-for-contact-form-7'), $age, $min),
                    'time_check',
                    ['signal' => 'js_age', 'age' => $age, 'min' => $min]
                );
            }
            if ($age > $maxAge) {
                return CheckResult::fail(
                    $this->id(),
                    sprintf(__('Form sat open for %d s — older than the %d s maximum.', 'spam-checker-for-contact-form-7'), $age, $maxAge),
                    'time_check',
                    ['signal' => 'js_age', 'age' => $age, 'max' => $maxAge]
                );
            }

            return CheckResult::pass($this->id(), ['signal' => 'js_age', 'age' => $age]);
        }

        // Signal 2: server render timestamp fallback.
        $ts = (int) ($context->postedData[self::FIELD_TS] ?? 0);
        if ($ts <= 0) {
            return CheckResult::skip($this->id(), __('No timing fields present (JS disabled or direct POST).', 'spam-checker-for-contact-form-7'));
        }

        $diff = $context->serverTime - $ts;

        if ($diff < $min && abs($diff) < $skew) {
            return CheckResult::fail(
                $this->id(),
                sprintf(__('Render-to-submit delta of %d s is below the %d s minimum.', 'spam-checker-for-contact-form-7'), $diff, $min),
                'time_check',
                ['signal' => 'render_ts', 'delta' => $diff, 'min' => $min]
            );
        }
        if ($diff > $maxAge + $skew) {
            return CheckResult::fail(
                $this->id(),
                sprintf(__('Form timestamp is %d s old — beyond the %d s freshness window.', 'spam-checker-for-contact-form-7'), $diff, $maxAge),
                'time_check',
                ['signal' => 'render_ts', 'delta' => $diff, 'max' => $maxAge]
            );
        }

        return CheckResult::pass($this->id(), ['signal' => 'render_ts', 'delta' => $diff]);
    }
}
