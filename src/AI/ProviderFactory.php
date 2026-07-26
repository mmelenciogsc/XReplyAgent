<?php

declare(strict_types=1);

namespace XReplyAgent\AI;

use XReplyAgent\Support\Settings;

final class ProviderFactory
{
    public static function make(): ProviderInterface
    {
        $settings = self::settings();
        if ($settings['mock_mode'] || $settings['api_key'] === '' || $settings['provider'] !== 'openai') {
            return new MockProvider();
        }

        return new OpenAIProvider(
            $settings['api_key'],
            $settings['endpoint'],
            $settings['model'],
            $settings['temperature']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $defaults = Settings::defaults();
        $key_file = (string) get_option(Settings::OPTION_API_KEY_FILE, $defaults[Settings::OPTION_API_KEY_FILE]);
        $api_key = trim((string) get_option(Settings::OPTION_API_KEY, ''));
        if ($api_key === '') {
            $api_key = OpenAIProvider::readKey($key_file);
        }

        return [
            'provider' => (string) get_option(Settings::OPTION_PROVIDER, $defaults[Settings::OPTION_PROVIDER]),
            'model' => (string) get_option(Settings::OPTION_MODEL, $defaults[Settings::OPTION_MODEL]),
            'endpoint' => (string) get_option(Settings::OPTION_ENDPOINT, $defaults[Settings::OPTION_ENDPOINT]),
            'api_key' => $api_key,
            'key_file' => $key_file,
            'temperature' => (float) get_option(Settings::OPTION_TEMPERATURE, $defaults[Settings::OPTION_TEMPERATURE]),
            'mock_mode' => Settings::bool(Settings::OPTION_MOCK_MODE),
            'daily_limit' => max(1, Settings::int(Settings::OPTION_DAILY_LIMIT)),
            'max_post_chars' => max(500, Settings::int(Settings::OPTION_MAX_POST_CHARS)),
            'max_context_chars' => max(250, Settings::int(Settings::OPTION_MAX_CONTEXT_CHARS)),
            'max_reply_chars' => max(80, Settings::int(Settings::OPTION_MAX_REPLY_CHARS)),
            'retention_days' => max(1, Settings::int(Settings::OPTION_RETENTION_DAYS)),
            'cost_prompt_per_1k' => max(0.0, Settings::float(Settings::OPTION_COST_PROMPT)),
            'cost_completion_per_1k' => max(0.0, Settings::float(Settings::OPTION_COST_COMPLETION)),
            'retain_on_uninstall' => Settings::bool(Settings::OPTION_RETAIN_ON_UNINSTALL),
            'public_access' => Settings::bool(Settings::OPTION_PUBLIC_ACCESS),
            'brand_label' => Settings::get(Settings::OPTION_BRAND_LABEL),
            'default_tone' => Settings::get(Settings::OPTION_DEFAULT_TONE),
        ];
    }
}
