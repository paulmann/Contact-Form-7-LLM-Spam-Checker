<?php
/**
 * Provider-agnostic LLM gateway for spam classification.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Services\Llm;

use CF7Sec\Settings;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Resolves the configured provider (DeepSeek, OpenAI, Qwen, Anthropic,
 * Gemini, Groq, Mistral, OpenRouter, xAI or any custom OpenAI-/Anthropic-
 * compatible endpoint), dispatches the classification request through the
 * matching universal protocol and parses the strict SPAM/CLEAN verdict.
 */
final class LlmGateway
{
    public const VERDICT_SPAM  = 'SPAM';
    public const VERDICT_CLEAN = 'CLEAN';

    private const SYSTEM_PROMPT = <<<'TXT'
You are a strict anti-spam classifier for a website contact form.
You will receive ONE submission as a structured digest: form fields, client IP,
geo/ISP data (when known), user agent, referrer, page dwell time and rate-limit context.

Decide whether the submission is SPAM (unsolicited advertising, phishing, malware links,
SEO link dumps, gibberish/bot-generated text, fraudulent offers, credential harvesting,
irrelevant mass-mail content) or CLEAN (a plausible message written by a real human,
even if short, emotional, in any language, or containing 0-1 legitimate links).

Rules:
- Judge by the totality of evidence; a single weak signal is not enough for SPAM.
- Never mark a message SPAM only because of an unusual language or a free-mail domain.
- Technical payloads inside the message (SQL, HTML, scripts) always mean SPAM.

Respond with EXACTLY one word and nothing else: SPAM or CLEAN.
TXT;

    public function __construct(private readonly Settings $settings)
    {
    }

    public static function systemPrompt(): string
    {
        return self::SYSTEM_PROMPT;
    }

    /** Currently selected provider id. */
    public function providerId(): string
    {
        $id = (string) $this->settings->get('llm.provider', 'deepseek');

        return ProviderCatalog::exists($id) ? $id : 'deepseek';
    }

    /**
     * Catalog entry of the current provider (never null).
     *
     * @return array{label: string, protocol: string, base: string, models: array<int, string>, key_url: string, note: string}
     */
    public function provider(): array
    {
        return ProviderCatalog::get($this->providerId()) ?? ProviderCatalog::PROVIDERS['deepseek'];
    }

    public function apiKey(): string
    {
        return (string) $this->settings->get('llm.api_keys.' . $this->providerId(), '');
    }

    public function model(): string
    {
        $model = (string) $this->settings->get('llm.models.' . $this->providerId(), '');
        if ($model !== '') {
            return $model;
        }

        return (string) ($this->provider()['models'][0] ?? '');
    }

    public function baseUrl(): string
    {
        $override = (string) $this->settings->get('llm.base_urls.' . $this->providerId(), '');

        return $override !== '' ? untrailingslashit($override) : (string) $this->provider()['base'];
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->baseUrl() !== '' && $this->model() !== '';
    }

    /**
     * Classify a submission digest with the configured provider.
     *
     * @return array{verdict: ?string, raw: string, model: string, ms: int, usage: array<string, int>, provider: string, protocol: string}|WP_Error
     */
    public function classify(string $digest): array|WP_Error
    {
        $provider = $this->provider();
        $model    = $this->model();
        $timeout  = max(5, (int) $this->settings->get('llm.timeout', 20));

        if ($provider['protocol'] === ProviderCatalog::PROTOCOL_ANTHROPIC) {
            $response = AnthropicMessagesClient::message(
                $this->baseUrl(),
                $this->apiKey(),
                $model,
                self::SYSTEM_PROMPT,
                $digest,
                $timeout,
            );
        } else {
            $extra = ['max_tokens' => 16];
            // Reasoning-style models reject/ignore sampling parameters.
            if (!$this->isReasoningModel($model)) {
                $extra['temperature'] = 0.0;
            }
            if ($this->isOpenAiReasoningModel($model)) {
                unset($extra['max_tokens']);
                $extra['max_completion_tokens'] = 32;
            }

            $response = OpenAiChatClient::chat(
                $this->baseUrl(),
                $this->apiKey(),
                $model,
                [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $digest],
                ],
                $timeout,
                $extra,
            );
        }

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'verdict'  => self::parseVerdict($response['content']),
            'raw'      => $response['content'],
            'model'    => $response['model'],
            'ms'       => $response['ms'],
            'usage'    => $response['usage'],
            'provider' => $this->providerId(),
            'protocol' => $provider['protocol'],
        ];
    }

    /**
     * Strict-but-forgiving verdict parser: the model must emit exactly one
     * of SPAM / CLEAN; anything ambiguous is rejected (null).
     */
    public static function parseVerdict(string $content): ?string
    {
        $clean = strtoupper(trim(preg_replace('/[^A-Za-z]/', ' ', $content) ?? ''));
        $hasSpam  = preg_match('/\bSPAM\b/', $clean) === 1;
        $hasClean = preg_match('/\bCLEAN\b/', $clean) === 1;

        if ($hasSpam && !$hasClean) {
            return self::VERDICT_SPAM;
        }
        if ($hasClean && !$hasSpam) {
            return self::VERDICT_CLEAN;
        }

        return null;
    }

    /**
     * Connectivity/credential self-test used by the admin "Test" button.
     *
     * @return array{ok: bool, message: string, latency_ms: int}|WP_Error
     */
    public function test(): array|WP_Error
    {
        $result = $this->classify(
            "Form fields:\n  subject: \"Test\"\n  message: \"Hello, this is a connectivity test from the plugin admin.\"\nClient IP: 203.0.113.7 (documentation range)\nUser-Agent: Mozilla/5.0 (compatible; CF7Sec-Selftest)"
        );

        if (is_wp_error($result)) {
            return $result;
        }
        if ($result['verdict'] === null) {
            return new WP_Error(
                'cf7sec_llm_parse',
                __('Model returned an unparsable verdict: ', 'contact-form-7-spam-checker') . $result['raw']
            );
        }

        return [
            'ok'         => true,
            'message'    => sprintf(
                /* translators: 1: provider label, 2: model name, 3: verdict, 4: latency in ms */
                __('%1$s / %2$s responded "%3$s" in %4$d ms.', 'contact-form-7-spam-checker'),
                $this->provider()['label'],
                $result['model'],
                $result['verdict'],
                $result['ms']
            ),
            'latency_ms' => $result['ms'],
        ];
    }

    /** DeepSeek-reasoner and friends: sampling parameters are not supported. */
    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/(reasoner|reasoning|^o\d|-think)/i', $model);
    }

    /** OpenAI o-series expects max_completion_tokens instead of max_tokens. */
    private function isOpenAiReasoningModel(string $model): bool
    {
        return $this->providerId() === 'openai' && (bool) preg_match('/^o\d/i', $model);
    }
}
