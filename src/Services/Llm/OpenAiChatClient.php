<?php
/**
 * OpenAI-compatible "chat completions" protocol client.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Services\Llm;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Speaks POST {base}/chat/completions with Bearer authentication — the de
 * facto universal LLM protocol implemented by DeepSeek, OpenAI, Qwen,
 * Gemini, Groq, Mistral, OpenRouter, xAI, vLLM, Ollama and dozens more.
 */
final class OpenAiChatClient
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $extra Extra body parameters.
     *
     * @return array{content: string, model: string, ms: int, usage: array<string, int>}|WP_Error
     */
    public static function chat(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        int $timeout = 20,
        array $extra = [],
    ): array|WP_Error {
        if ($apiKey === '') {
            return new WP_Error('cf7sec_llm_no_key', __('The API key for the selected LLM provider is not set.', 'contact-form-7-spam-checker'));
        }
        if ($baseUrl === '') {
            return new WP_Error('cf7sec_llm_no_base', __('The API base URL for the selected LLM provider is not set.', 'contact-form-7-spam-checker'));
        }

        $body = array_merge([
            'model'    => $model,
            'messages' => $messages,
        ], $extra);

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        // OpenRouter asks for attribution headers (optional but polite).
        if (str_contains($baseUrl, 'openrouter.ai')) {
            $headers['HTTP-Referer'] = home_url();
            $headers['X-Title']      = 'CF7 Spam Checker Pro';
        }

        $start = hrtime(true);
        $http  = wp_remote_post(untrailingslashit($baseUrl) . '/chat/completions', [
            'timeout' => $timeout,
            'headers' => $headers,
            'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);

        if (is_wp_error($http)) {
            return new WP_Error('cf7sec_llm_http', $http->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($http);
        $raw    = (string) wp_remote_retrieve_body($http);
        $json   = json_decode($raw, true);

        if ($status !== 200 || !is_array($json)) {
            $message = is_array($json) && isset($json['error']['message'])
                ? (string) $json['error']['message']
                : mb_substr($raw, 0, 300);

            return new WP_Error('cf7sec_llm_status', sprintf('LLM API error (HTTP %d): %s', $status, $message));
        }

        $content = (string) ($json['choices'][0]['message']['content'] ?? '');

        return [
            'content' => trim($content),
            'model'   => (string) ($json['model'] ?? $model),
            'ms'      => $ms,
            'usage'   => [
                'prompt_tokens'     => (int) ($json['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
                'total_tokens'      => (int) ($json['usage']['total_tokens'] ?? 0),
            ],
        ];
    }
}
