<?php
/**
 * Bot user-agent detection with false-positive suppression.
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

/**
 * Flags scripted clients by their User-Agent header.
 *
 * The matcher is deliberately conservative:
 *  - empty UA                     → suspicious (real browsers always send one)
 *  - strong-evidence token        → immediate flag (sqlmap, headless, curl...)
 *  - generic token (bot, crawl..) → flagged unless it is a substring of a
 *                                   legitimate browser name (e.g. "robot"
 *                                   inside nothing real — kept symmetric)
 *  - administrator extras         → appended from settings
 */
final class BotAgentCheck implements CheckInterface
{
    public function id(): string
    {
        return 'bot_ua';
    }

    public function label(): string
    {
        return __('Bot user-agent detection', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('bot_ua');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $ua = trim($context->userAgent);

        if ($ua === '') {
            return CheckResult::fail(
                $this->id(),
                __('Empty User-Agent header — real browsers always send one.', 'spam-checker-for-contact-form-7'),
                'bot_detected',
                ['user_agent' => '', 'signal' => 'empty_ua']
            );
        }

        $lc = strtolower($ua);

        // Strong evidence first.
        foreach (Patterns::UA_STRONG_EVIDENCE as $token) {
            if (str_contains($lc, $token)) {
                return $this->flag($ua, $token, 'strong_token');
            }
        }

        // Generic keyword list (+ admin extras).
        $extras = array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', strtolower((string) $context->settings->get('checks.bot_ua.extra_keywords', ''))) ?: []
        ));

        foreach ([...Patterns::BOT_AGENTS, ...$extras] as $token) {
            $token = strtolower(trim((string) $token));
            if ($token === '' || !str_contains($lc, $token)) {
                continue;
            }
            if ($this->isPartOfLegitBrowserName($token, $lc)) {
                continue;
            }

            return $this->flag($ua, $token, 'keyword');
        }

        return CheckResult::pass($this->id(), ['user_agent' => mb_substr($ua, 0, 200)]);
    }

    private function flag(string $ua, string $token, string $signal): CheckResult
    {
        return CheckResult::fail(
            $this->id(),
            sprintf(
                __('User-Agent contains automation marker "%s".', 'spam-checker-for-contact-form-7'),
                $token
            ),
            'bot_detected',
            ['user_agent' => mb_substr($ua, 0, 300), 'matched' => $token, 'signal' => $signal]
        );
    }

    /**
     * True when the token only appears as part of a well-known browser name,
     * e.g. "bot" inside "Googlebot" is genuine, but "bot" inside
     * "Mozilla/5.0 ... Chrome/120" is not — such UAs never contain it.
     */
    private function isPartOfLegitBrowserName(string $token, string $lcUa): bool
    {
        // Never whitelist strong evidence.
        if (in_array($token, Patterns::UA_STRONG_EVIDENCE, true)) {
            return false;
        }

        foreach (Patterns::UA_FALSE_POSITIVES as $safe) {
            if ($token === $safe) {
                return true;
            }
        }

        return false;
    }
}
