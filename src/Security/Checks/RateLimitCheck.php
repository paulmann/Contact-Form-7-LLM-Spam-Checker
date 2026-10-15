<?php
/**
 * Sliding-window rate limiter (per IP, transient-backed).
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;
use CF7Sec\Support\Ip;

defined('ABSPATH') || exit;

/**
 * Counts submissions per IP inside a 60-second sliding window stored in a
 * transient. Exceeding the limit flags the submission and registers a strike
 * with the BanManager (handled by the pipeline).
 */
final class RateLimitCheck implements CheckInterface
{
    public function id(): string
    {
        return 'rate_limit';
    }

    public function label(): string
    {
        return __('Rate limiting', 'contact-form-7-spam-checker');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('rate_limit');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $max = max(1, (int) $context->settings->get('checks.rate_limit.max_per_minute', 20));
        $key = 'cf7sec_rl_' . substr(Ip::hash($context->ip), 0, 32);

        /** @var array<int, int> $hits Timestamps inside the window. */
        $hits   = (array) get_transient($key);
        $now    = $context->serverTime;
        $window = $now - 60;
        $hits   = array_values(array_filter($hits, static fn(int $t): bool => $t > $window));
        $hits[] = $now;
        set_transient($key, $hits, 120);

        $count = count($hits);

        if ($count > $max) {
            return CheckResult::fail(
                $this->id(),
                sprintf(
                    __('%1$d submissions in the last 60 s (limit: %2$d).', 'contact-form-7-spam-checker'),
                    $count,
                    $max
                ),
                'rate_limit',
                ['count' => $count, 'max' => $max, 'window' => 60]
            );
        }

        return CheckResult::pass($this->id(), ['count' => $count, 'max' => $max]);
    }
}
