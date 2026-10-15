<?php
/**
 * Builds the structured submission digest sent to the LLM.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security;

defined('ABSPATH') || exit;

/**
 * Serialises a SubmissionContext into a compact, prompt-friendly digest:
 * fields + IP + geo + user-agent + referrer + timing + prior local verdicts.
 */
final class LlmPrompt
{
    public static function digest(SubmissionContext $context): string
    {
        $lines   = [];
        $lines[] = 'FORM SUBMISSION DIGEST';
        $lines[] = '======================';
        $lines[] = 'Form ID: ' . $context->formId;
        if ($context->formTitle !== '') {
            $lines[] = 'Form title: ' . $context->formTitle;
        }
        $lines[] = 'Submitted at (UTC): ' . gmdate('Y-m-d H:i:s', $context->serverTime);
        $lines[] = '';
        $lines[] = 'CLIENT';
        $lines[] = 'IP address: ' . $context->ip;

        $geo = $context->geo();
        if ($geo !== null && !empty($geo['country_code'])) {
            $lines[] = 'Country: ' . $geo['country_code'] . ' (' . ($geo['country_name'] ?? '') . ')';
            if (!empty($geo['isp'])) {
                $lines[] = 'ISP: ' . $geo['isp'];
            }
            if (!empty($geo['usage_type'])) {
                $lines[] = 'Usage type: ' . $geo['usage_type'];
            }
            $lines[] = 'IP abuse confidence score: ' . (int) ($geo['abuse_score'] ?? 0) . '%';
            $lines[] = 'IP total abuse reports: ' . (int) ($geo['total_reports'] ?? 0);
            if (!empty($geo['is_tor'])) {
                $lines[] = 'Tor exit node: yes';
            }
        }

        $lines[] = 'User-Agent: ' . mb_substr($context->userAgent ?: '(empty)', 0, 300);
        $lines[] = 'Referrer: ' . mb_substr($context->referrer ?: '(none)', 0, 300);

        // Timing signals.
        $age = $context->postedData[Checks\TimeCheck::FIELD_AGE] ?? null;
        if ($age !== null && is_numeric($age)) {
            $lines[] = 'Seconds between page load and submit: ' . (int) $age;
        }

        // Local pre-check verdicts (so the LLM sees the full picture).
        if ($context->collected !== []) {
            $summary = [];
            foreach ($context->collected as $r) {
                if ($r->status !== CheckStatus::Skip) {
                    $summary[] = $r->checkId . '=' . $r->status->value;
                }
            }
            if ($summary !== []) {
                $lines[] = 'Local heuristic results: ' . implode(', ', $summary);
            }
        }

        $lines[] = '';
        $lines[] = 'SUBMITTED FIELDS';

        $fields = $context->llmFields();
        if ($fields === []) {
            $lines[] = '(none)';
        }
        foreach ($fields as $name => $value) {
            $value = str_replace(["\r\n", "\r", "\n"], ' ⏎ ', $value);
            $lines[] = '- ' . $name . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = 'Respond with exactly one word: SPAM or CLEAN.';

        $digest = implode("\n", $lines);

        // Hard cap: keep the prompt well below model limits (~8k chars ≈ 3k tokens).
        return mb_substr($digest, 0, 12000);
    }
}
