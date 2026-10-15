<?php
/**
 * IP ban-list check.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;
use CF7Sec\Storage\IpRuleRepository;

defined('ABSPATH') || exit;

final class IpBanCheck implements CheckInterface
{
    public function id(): string
    {
        return 'ip_rules';
    }

    public function label(): string
    {
        return __('IP ban list', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('ip_rules');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $rule = IpRuleRepository::find($context->ip);

        if ($rule === null || $rule->list_type !== IpRuleRepository::TYPE_BAN) {
            return CheckResult::pass($this->id());
        }

        $permanent = $rule->expires_at === null;
        $expired   = !$permanent && strtotime((string) $rule->expires_at . ' UTC') <= time();

        if ($expired) {
            IpRuleRepository::delete($context->ip);

            return CheckResult::pass($this->id(), ['expired_ban_removed' => true]);
        }

        return CheckResult::fail(
            $this->id(),
            sprintf(
                __('IP %1$s is banned (reason: %2$s, source: %3$s).', 'spam-checker-for-contact-form-7'),
                $context->ip,
                (string) $rule->reason,
                (string) $rule->source
            ),
            'ip_blocked',
            [
                'ip'        => $context->ip,
                'reason'    => (string) $rule->reason,
                'source'    => (string) $rule->source,
                'permanent' => $permanent,
                'expires'   => $rule->expires_at,
            ]
        );
    }
}
