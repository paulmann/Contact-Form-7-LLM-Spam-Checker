<?php
/**
 * The spam-check pipeline hooked into `wpcf7_spam`.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security;

use CF7Sec\Security\Checks\AbuseIpCheck;
use CF7Sec\Security\Checks\BotAgentCheck;
use CF7Sec\Security\Checks\HoneypotCheck;
use CF7Sec\Security\Checks\IpBanCheck;
use CF7Sec\Security\Checks\KeywordSpamCheck;
use CF7Sec\Security\Checks\LinkSpamCheck;
use CF7Sec\Security\Checks\LlmCheck;
use CF7Sec\Security\Checks\RateLimitCheck;
use CF7Sec\Security\Checks\SqlInjectionCheck;
use CF7Sec\Security\Checks\TimeCheck;
use CF7Sec\Security\Checks\XssCheck;
use CF7Sec\Services\AbuseIpDb;
use CF7Sec\Services\BanManager;
use CF7Sec\Services\Llm\LlmGateway;
use CF7Sec\Services\Logger;
use CF7Sec\Services\Mailer;
use CF7Sec\Settings;
use CF7Sec\Storage\DailyStats;
use CF7Sec\Storage\EventsRepository;
use CF7Sec\Storage\IpRuleRepository;
use CF7Sec\Support\Ip;
use Throwable;
use WPCF7_Submission;

defined('ABSPATH') || exit;

/**
 * Orchestrates every check for one submission, aggregates the verdict and
 * performs side effects (registry, statistics, strikes, bans, notifications).
 *
 * Pipeline order:
 *   0. Whitelist short-circuit
 *   1. Local checks (cheap): IP ban → timing → honeypot → bot UA → rate
 *      limit → SQLi → XSS → links → keywords
 *   2. Remote checks (expensive, only while still clean unless
 *      `llm.run_when_flagged`): AbuseIPDB → LLM gateway
 *
 * Fail-safe: any unexpected internal error fails OPEN (submission allowed)
 * and is written to the registry — security tooling must never take a
 * contact form down.
 */
final class SpamPipeline
{
    /** @var array<int, CheckInterface> */
    private array $localChecks;

    /** @var array<int, CheckInterface> */
    private array $remoteChecks;

    /** Message key of the first failed check (consumed by the display-message filter). */
    private ?string $lastMessageKey = null;

    /** @var array<string, mixed> Details for the display-message filter. */
    private array $lastMessageContext = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly BanManager $banManager,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly AbuseIpDb $abuseIpDb,
        LlmGateway $llmGateway,
    ) {
        $this->localChecks = [
            new IpBanCheck(),
            new TimeCheck(),
            new HoneypotCheck(),
            new BotAgentCheck(),
            new RateLimitCheck(),
            new SqlInjectionCheck(),
            new XssCheck(),
            new LinkSpamCheck(),
            new KeywordSpamCheck(),
        ];
        $this->remoteChecks = [
            new AbuseIpCheck($abuseIpDb),
            new LlmCheck($llmGateway),
        ];
    }

    /**
     * `wpcf7_spam` filter callback.
     */
    public function filter(bool $spam, WPCF7_Submission $submission): bool
    {
        if ($spam) {
            return true; // Another plugin already flagged it.
        }

        try {
            return $this->process($submission);
        } catch (Throwable $e) {
            // Fail open, but never silently.
            $this->logger->debug('PIPELINE_EXCEPTION', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            EventsRepository::record([
                'event_type' => 'internal_error',
                'verdict'    => EventsRepository::VERDICT_ERROR,
                'ip'         => Ip::clientFromSettings($this->settings),
                'reasons'    => ['Unhandled exception: ' . $e->getMessage()],
            ]);

            return false;
        }
    }

    private function process(WPCF7_Submission $submission): bool
    {
        $form    = $submission->get_contact_form();
        $formId  = (int) $form->id();

        if (!$this->formIsProtected($formId)) {
            return false;
        }

        $context = new SubmissionContext(
            formId: $formId,
            formTitle: (string) $form->title(),
            postedData: $submission->get_posted_data(),
            ip: Ip::clientFromSettings($this->settings),
            userAgent: (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            referrer: (string) $submission->get_meta('referer'),
            serverTime: time(),
            settings: $this->settings,
        );

        DailyStats::bump(gmdate('Y-m-d'), ['processed' => 1]);

        // 0. Whitelist short-circuit.
        if (IpRuleRepository::isWhitelisted($context->ip)) {
            $this->logger->debug('WHITELIST_BYPASS', ['ip' => $context->ip, 'form' => $formId]);
            $this->maybeLogClean($context, []);

            return false;
        }

        // 1. Local checks.
        $results = [];
        $flagged = false;
        foreach ($this->localChecks as $check) {
            if (!$check->enabled($context)) {
                $results[] = CheckResult::skip($check->id(), __('Disabled', 'contact-form-7-spam-checker'));
                continue;
            }
            $result    = $check->run($context);
            $results[] = $result;
            $context->collected = $results;

            if ($result->isFail()) {
                $flagged = true;
                $this->captureMessage($result);
                // IP-ban / honeypot hits are decisive; content checks keep
                // running so the registry contains the full picture, but we
                // stop before expensive remote calls when already flagged.
            }
        }

        // 2. Remote checks.
        $runRemoteWhenFlagged = (bool) $this->settings->get('deepseek.run_when_flagged', false);
        foreach ($this->remoteChecks as $check) {
            if (!$check->enabled($context)) {
                $results[] = CheckResult::skip($check->id(), __('Disabled or not configured', 'contact-form-7-spam-checker'));
                continue;
            }
            if ($flagged && !$runRemoteWhenFlagged) {
                $results[] = CheckResult::skip($check->id(), __('Skipped — submission already flagged', 'contact-form-7-spam-checker'));
                continue;
            }

            $result    = $check->run($context);
            $results[] = $result;
            $context->collected = $results;

            if ($result->isFail() && !$flagged) {
                $flagged = true;
                $this->captureMessage($result);
            }
            if ($result->status === CheckStatus::Error) {
                $this->onRemoteError($check->id(), $result);
            }
        }

        // 3. Aggregate + side effects.
        $fails = array_values(array_filter($results, static fn(CheckResult $r): bool => $r->isFail()));

        if ($flagged) {
            $this->onSpam($context, $results, $fails);

            return true;
        }

        $this->onClean($context, $results);

        return false;
    }

    private function formIsProtected(int $formId): bool
    {
        $mode = (string) $this->settings->get('general.protect_forms', 'all');
        if ($mode !== 'selected') {
            return true;
        }

        return in_array($formId, array_map('intval', (array) $this->settings->get('general.form_ids', [])), true);
    }

    private function captureMessage(CheckResult $result): void
    {
        if ($this->lastMessageKey === null && $result->messageKey !== '') {
            $this->lastMessageKey     = $result->messageKey;
            $this->lastMessageContext = $result->details;
        }
    }

    /**
     * User-facing spam message for the current request (consumed by the
     * `wpcf7_display_message` filter registered in Plugin::boot()).
     */
    public function frontendMessage(string $fallback): string
    {
        if ($this->lastMessageKey === null) {
            return $fallback;
        }

        $message = (string) $this->settings->get('messages.' . $this->lastMessageKey, '');
        if ($message === '') {
            $message = (string) (Settings::DEFAULTS['messages'][$this->lastMessageKey] ?? $fallback);
        }

        $minutes = max(1, (int) ceil(((int) $this->settings->get('checks.rate_limit.ban_duration_hours', 1) * 3600) / 60));
        $message = str_replace(
            ['%minutes%', '%field_name%', '%language_name%'],
            [(string) $minutes, (string) ($this->lastMessageContext['field'] ?? ''), (string) ($this->lastMessageContext['language_name'] ?? '')],
            $message
        );

        return $message !== '' ? $message : $fallback;
    }

    /**
     * @param array<int, CheckResult> $results
     * @param array<int, CheckResult> $fails
     */
    private function onSpam(SubmissionContext $context, array $results, array $fails): void
    {
        DailyStats::bump(gmdate('Y-m-d'), ['blocked' => 1]);

        $reasons = array_map(static fn(CheckResult $r): string => $r->reason, $fails);
        $failIds = array_map(static fn(CheckResult $r): string => $r->checkId, $fails);

        EventsRepository::record([
            'form_id'    => $context->formId,
            'ip'         => $context->ip,
            'country'    => $context->country(),
            'event_type' => 'spam_blocked',
            'verdict'    => EventsRepository::VERDICT_BLOCKED,
            'reasons'    => $reasons,
            'checks'     => array_map(static fn(CheckResult $r): array => $r->toArray(), $results),
            'payload'    => $this->sanitizePayload($context),
            'user_agent' => $context->userAgent,
            'referrer'   => $context->referrer,
        ]);

        $this->logger->debug('SPAM_BLOCKED', [
            'form' => $context->formId, 'ip' => $context->ip, 'checks' => $failIds,
        ]);

        // Strikes & auto-ban (whitelisted IPs never reach here).
        $this->banManager->strike($context->ip, $reasons[0] ?? 'spam');

        if (in_array('abuseipdb', $failIds, true) && $this->settings->get('abuseipdb.auto_ban', false)) {
            $this->banManager->ban($context->ip, __('AbuseIPDB reputation below threshold', 'contact-form-7-spam-checker'), 'abuseipdb');
        }
        if (in_array('llm', $failIds, true) && $this->settings->get('llm.ban_on_spam', false)) {
            $this->banManager->ban($context->ip, sprintf(__('LLM verdict: SPAM (%s)', 'contact-form-7-spam-checker'), (string) $this->settings->get('llm.provider', '')), 'llm');
        }

        // Optional: report confirmed spammers back to AbuseIPDB.
        if ($this->settings->get('abuseipdb.report_spam', false) && $this->settings->get('abuseipdb.api_key', '') !== '') {
            $this->reportToAbuseIpDb($context, $reasons);
        }

        if ($this->settings->get('notifications.enabled', false)
            && in_array('spam_detected', (array) $this->settings->get('notifications.events', []), true)) {
            $this->mailer->notify(
                'spam_detected',
                /* translators: %s: form ID */
                sprintf(__('Spam blocked on form #%s', 'contact-form-7-spam-checker'), (string) $context->formId),
                implode("\n", [
                    'IP: ' . $context->ip,
                    'Country: ' . ($context->country() ?: '—'),
                    ...array_map(static fn(string $r): string => '• ' . $r, $reasons),
                ])
            );
        }
    }

    /**
     * @param array<int, CheckResult> $results
     */
    private function onClean(SubmissionContext $context, array $results): void
    {
        $this->maybeLogClean($context, $results);
    }

    /**
     * @param array<int, CheckResult> $results
     */
    private function maybeLogClean(SubmissionContext $context, array $results): void
    {
        if ((string) $this->settings->get('general.log_verdicts', 'blocked') !== 'all') {
            return;
        }

        EventsRepository::record([
            'form_id'    => $context->formId,
            'ip'         => $context->ip,
            'country'    => $context->country(),
            'event_type' => 'clean',
            'verdict'    => EventsRepository::VERDICT_ALLOWED,
            'reasons'    => [],
            'checks'     => array_map(static fn(CheckResult $r): array => $r->toArray(), $results),
            'payload'    => $this->sanitizePayload($context),
            'user_agent' => $context->userAgent,
            'referrer'   => $context->referrer,
        ]);
    }

    private function onRemoteError(string $checkId, CheckResult $result): void
    {
        EventsRepository::record([
            'event_type' => $checkId === 'llm' ? 'llm_error' : 'abuse_error',
            'verdict'    => EventsRepository::VERDICT_ERROR,
            'reasons'    => [$result->reason],
            'checks'     => [$result->toArray()],
        ]);

        $notifyKey = $checkId === 'llm' ? 'llm_error' : 'abuse_error';
        if ($this->settings->get('notifications.enabled', false)
            && in_array($notifyKey, (array) $this->settings->get('notifications.events', []), true)) {
            $this->mailer->notify(
                $notifyKey,
                sprintf(
                    /* translators: %s: integration name */
                    __('Integration error: %s', 'contact-form-7-spam-checker'),
                    $checkId === 'llm' ? 'DeepSeek LLM' : 'AbuseIPDB'
                ),
                $result->reason
            );
        }

        $this->logger->debug('REMOTE_CHECK_ERROR', ['check' => $checkId, 'reason' => $result->reason]);
    }

    /**
     * @param array<int, string> $reasons
     */
    private function reportToAbuseIpDb(SubmissionContext $context, array $reasons): void
    {
        static $reported = [];
        if (isset($reported[$context->ip])) {
            return; // one report per request
        }
        $reported[$context->ip] = true;

        $comment = sprintf(
            'Spam submission blocked by CF7 Spam Checker Pro on form #%d at %s. Reasons: %s',
            $context->formId,
            wp_date('Y-m-d H:i'),
            mb_substr(implode('; ', $reasons), 0, 700)
        );

        $ok = $this->abuseIpDb->report($context->ip, $comment);
        $this->logger->debug('ABUSEIPDB_REPORT', [
            'ip' => $context->ip,
            'ok' => !is_wp_error($ok) ? 'yes' : $ok->get_error_message(),
        ]);
    }

    /**
     * Registry payload: field values, with secrets and huge blobs removed.
     *
     * @return array<string, mixed>
     */
    private function sanitizePayload(SubmissionContext $context): array
    {
        $out = [];
        foreach ($context->postedData as $key => $value) {
            $key = (string) $key;
            if (preg_match('/pass|pwd|secret|token|card|cvv/i', $key)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $value      = (string) $value;
            $out[$key] = mb_substr($value, 0, 2000) . (mb_strlen($value) > 2000 ? '…' : '');
        }

        $json = (string) wp_json_encode($out, JSON_UNESCAPED_UNICODE);

        // Hard cap stored payload at ~64 KB.
        return mb_strlen($json) > 65536 ? ['_truncated' => mb_substr($json, 0, 65536)] : $out;
    }
}
