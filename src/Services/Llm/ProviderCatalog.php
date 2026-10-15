<?php
/**
 * Catalog of supported LLM providers and the two universal protocols
 * (OpenAI-compatible chat completions & Anthropic Messages) they speak.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Services\Llm;

defined('ABSPATH') || exit;

/**
 * Every entry maps a provider id to:
 *  - protocol   : 'openai' (POST {base}/chat/completions, Bearer auth)
 *                 or 'anthropic' (POST {base}/v1/messages, x-api-key auth)
 *  - base       : default API base URL (overridable per installation)
 *  - models     : suggested model ids (the UI offers them as datalist hints;
 *                 any custom id is accepted — operators ship new models weekly)
 *  - key_url    : where administrators create API keys
 *  - note       : optional translatable hint shown in the admin UI
 *
 * The two "custom_*" entries are the universal escape hatches: any operator
 * exposing an OpenAI-compatible or Anthropic-compatible endpoint can be
 * plugged in by setting base URL + model + key.
 */
final class ProviderCatalog
{
    public const PROTOCOL_OPENAI    = 'openai';
    public const PROTOCOL_ANTHROPIC = 'anthropic';

    /**
     * @var array<string, array{label: string, protocol: string, base: string, models: array<int, string>, key_url: string, note: string}>
     */
    public const PROVIDERS = [
        'deepseek' => [
            'label'    => 'DeepSeek',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://api.deepseek.com',
            'models'   => ['deepseek-chat', 'deepseek-reasoner'],
            'key_url'  => 'https://platform.deepseek.com/api_keys',
            'note'     => '',
        ],
        'openai' => [
            'label'    => 'OpenAI (ChatGPT)',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://api.openai.com/v1',
            'models'   => ['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4.1', 'o4-mini'],
            'key_url'  => 'https://platform.openai.com/api-keys',
            'note'     => '',
        ],
        'qwen' => [
            'label'    => 'Qwen (Alibaba DashScope)',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
            'models'   => ['qwen-turbo', 'qwen-plus', 'qwen-max', 'qwen3-max'],
            'key_url'  => 'https://www.alibabacloud.com/product/modelstudio',
            'note'     => 'International endpoint by default; mainland-China users should override the base URL to https://dashscope.aliyuncs.com/compatible-mode/v1.',
        ],
        'anthropic' => [
            'label'    => 'Anthropic (Claude)',
            'protocol' => self::PROTOCOL_ANTHROPIC,
            'base'     => 'https://api.anthropic.com',
            'models'   => ['claude-3-5-haiku-latest', 'claude-sonnet-4-5', 'claude-opus-4-1'],
            'key_url'  => 'https://console.anthropic.com/settings/keys',
            'note'     => '',
        ],
        'gemini' => [
            'label'    => 'Google Gemini',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'models'   => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
            'key_url'  => 'https://aistudio.google.com/apikey',
            'note'     => '',
        ],
        'groq' => [
            'label'    => 'Groq',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://api.groq.com/openai/v1',
            'models'   => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'],
            'key_url'  => 'https://console.groq.com/keys',
            'note'     => '',
        ],
        'mistral' => [
            'label'    => 'Mistral AI',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://api.mistral.ai/v1',
            'models'   => ['mistral-small-latest', 'mistral-large-latest'],
            'key_url'  => 'https://console.mistral.ai/api-keys',
            'note'     => '',
        ],
        'openrouter' => [
            'label'    => 'OpenRouter (any model)',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://openrouter.ai/api/v1',
            'models'   => ['openai/gpt-4o-mini', 'deepseek/deepseek-chat', 'anthropic/claude-3.5-haiku', 'meta-llama/llama-3.3-70b-instruct'],
            'key_url'  => 'https://openrouter.ai/keys',
            'note'     => 'One key, hundreds of models — enter any OpenRouter model id.',
        ],
        'xai' => [
            'label'    => 'xAI (Grok)',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => 'https://api.x.ai/v1',
            'models'   => ['grok-3-mini', 'grok-3'],
            'key_url'  => 'https://console.x.ai',
            'note'     => '',
        ],
        'custom_openai' => [
            'label'    => 'Custom — any OpenAI-compatible API',
            'protocol' => self::PROTOCOL_OPENAI,
            'base'     => '',
            'models'   => [],
            'key_url'  => '',
            'note'     => 'Works with any operator exposing POST {base}/chat/completions and Bearer auth: Together, Fireworks, Perplexity, self-hosted vLLM/Ollama/LM Studio gateways, Azure-style proxies…',
        ],
        'custom_anthropic' => [
            'label'    => 'Custom — any Anthropic-compatible API',
            'protocol' => self::PROTOCOL_ANTHROPIC,
            'base'     => '',
            'models'   => [],
            'key_url'  => '',
            'note'     => 'Works with any gateway exposing POST {base}/v1/messages with x-api-key auth (proxies, Bedrock/Vertex Anthropic-compatible endpoints…).',
        ],
    ];

    public static function ids(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * @return array{label: string, protocol: string, base: string, models: array<int, string>, key_url: string, note: string}|null
     */
    public static function get(string $id): ?array
    {
        return self::PROVIDERS[$id] ?? null;
    }

    public static function label(string $id): string
    {
        return self::PROVIDERS[$id]['label'] ?? $id;
    }

    public static function exists(string $id): bool
    {
        return isset(self::PROVIDERS[$id]);
    }
}
