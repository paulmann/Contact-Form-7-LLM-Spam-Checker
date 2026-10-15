<?php
/**
 * Anthropic "Messages" protocol client.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Services\Llm;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Speaks POST {base}/v1/messages with x-api-key + anthropic-version headers —
 * the second universal protocol, used by Anthropic Claude directly and by
 * Anthropic-compatible gateways (proxies, cloud endpoints).
 */
final class AnthropicMessagesClient
{
    private const API_VERSION = '2023-06-01';

    /**
     * @return array{content: string, model: string, ms: int, usage: array<string, int>}|WP_Error
     */
    public static function message(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $system,
        string $user,
        int $timeout = 20,
        int $maxTokens = 32,
    ): array|WP_Error {
        if ($apiKey === '') {
            return new WP_Error('cf7sec_llm_no_key', __('The API key for the selected LLM provider is not set.', 'spam-checker-for-contact-form-7'));
        }
        if ($baseUrl === '') {
            return new WP_Error('cf7sec_llm_no_base', __('The API base URL for the selected LLM provider is not set.', 'spam-checker-for-contact-form-7'));
        }

        $base = untrailingslashit($baseUrl);
        if (!str_ends_with($base, '/v1')) {
            $base .= '/v1';
        }

        $start = hrtime(true);
        $http  = wp_remote_post($base . '/messages', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
            ],
            'body'    => wp_json_encode([
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $user],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

        // content is an array of blocks; concatenate the text ones.
        $content = '';
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $content .= (string) ($block['text'] ?? '');
            }
        }

        return [
            'content' => trim($content),
            'model'   => (string) ($json['model'] ?? $model),
            'ms'      => $ms,
            'usage'   => [
                'prompt_tokens'     => (int) ($json['usage']['input_tokens'] ?? 0),
                'completion_tokens' => (int) ($json['usage']['output_tokens'] ?? 0),
                'total_tokens'      => (int) ($json['usage']['input_tokens'] ?? 0) + (int) ($json['usage']['output_tokens'] ?? 0),
            ],
        ];
    }
}
