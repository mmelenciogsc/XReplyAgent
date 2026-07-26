<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class Settings
{
    public const OPTION_PROVIDER = 'xra_ai_provider';
    public const OPTION_MODEL = 'xra_ai_model';
    public const OPTION_ENDPOINT = 'xra_ai_endpoint';
    public const OPTION_API_KEY = 'xra_ai_api_key';
    public const OPTION_API_KEY_FILE = 'xra_ai_api_key_file';
    public const OPTION_TEMPERATURE = 'xra_ai_temperature';
    public const OPTION_MOCK_MODE = 'xra_mock_mode';
    public const OPTION_DAILY_LIMIT = 'xra_ai_daily_limit';
    public const OPTION_MAX_QUERIES = 'xra_max_queries';
    public const OPTION_MAX_POST_CHARS = 'xra_max_post_chars';
    public const OPTION_MAX_CONTEXT_CHARS = 'xra_max_context_chars';
    public const OPTION_MAX_REPLY_CHARS = 'xra_max_reply_chars';
    public const OPTION_RETENTION_DAYS = 'xra_retention_days';
    public const OPTION_COST_PROMPT = 'xra_cost_per_1k_prompt_tokens';
    public const OPTION_COST_COMPLETION = 'xra_cost_per_1k_completion_tokens';
    public const OPTION_RETAIN_ON_UNINSTALL = 'xra_retain_data_on_uninstall';
    public const OPTION_BRAND_LABEL = 'xra_brand_label';
    public const OPTION_DEFAULT_TONE = 'xra_default_tone';
    public const OPTION_PUBLIC_ACCESS = 'xra_public_access';
    public const OPTION_PUBLIC_QUERIES = 'xra_public_queries';
    public const OPTION_BROWSER_ENABLED = 'xra_browser_enabled';
    public const OPTION_BROWSER_PROFILE_DIR = 'xra_browser_profile_dir';
    public const OPTION_BROWSER_STORAGE_STATE = 'xra_browser_storage_state';
    public const OPTION_BROWSER_MONITOR_INTERVAL = 'xra_browser_monitor_interval_seconds';
    public const OPTION_BROWSER_MONITOR_CYCLES = 'xra_browser_monitor_cycles';
    public const OPTION_BROWSER_PUBLISH_SELECTORS = 'xra_browser_publish_selectors';

    public static function defaults(): array
    {
        return [
            self::OPTION_PROVIDER => 'openai',
            self::OPTION_MODEL => 'gpt-4o-mini',
            self::OPTION_ENDPOINT => 'https://api.openai.com/v1/responses',
            self::OPTION_API_KEY => '',
            self::OPTION_API_KEY_FILE => '/path/to/private-api-key.txt',
            self::OPTION_TEMPERATURE => '0.2',
            self::OPTION_MOCK_MODE => '0',
            self::OPTION_DAILY_LIMIT => '100',
            self::OPTION_MAX_QUERIES => '100',
            self::OPTION_MAX_POST_CHARS => '8000',
            self::OPTION_MAX_CONTEXT_CHARS => '4000',
            self::OPTION_MAX_REPLY_CHARS => '280',
            self::OPTION_RETENTION_DAYS => '365',
            self::OPTION_COST_PROMPT => '0.0',
            self::OPTION_COST_COMPLETION => '0.0',
            self::OPTION_RETAIN_ON_UNINSTALL => '1',
            self::OPTION_BRAND_LABEL => 'XReplyAgent',
            self::OPTION_DEFAULT_TONE => 'measured',
            self::OPTION_PUBLIC_ACCESS => '1',
            self::OPTION_PUBLIC_QUERIES => '1',
            self::OPTION_BROWSER_ENABLED => '1',
            self::OPTION_BROWSER_PROFILE_DIR => '',
            self::OPTION_BROWSER_STORAGE_STATE => '',
            self::OPTION_BROWSER_MONITOR_INTERVAL => '20',
            self::OPTION_BROWSER_MONITOR_CYCLES => '3',
            self::OPTION_BROWSER_PUBLISH_SELECTORS => json_encode([
                'compose' => [
                    '[data-testid="tweetTextarea_0"]',
                    '[data-testid="tweetTextarea_0"] div[contenteditable="true"]',
                    'div[role="textbox"][contenteditable="true"]',
                    'textarea',
                ],
                'publish' => [
                    '[data-testid="tweetButton"]',
                    '[data-testid="tweetButtonInline"]',
                    'button[type="submit"]',
                    'button[aria-label*="Post"]',
                ],
                'reply' => [
                    '[data-testid="tweetTextarea_0"]',
                    'div[role="textbox"][contenteditable="true"]',
                    'textarea',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ];
    }

    public static function get(string $option): string
    {
        $defaults = self::defaults();
        return (string) get_option($option, $defaults[$option] ?? '');
    }

    public static function bool(string $option): bool
    {
        return self::get($option) === '1';
    }

    public static function int(string $option): int
    {
        return (int) self::get($option);
    }

    public static function float(string $option): float
    {
        return (float) self::get($option);
    }
}
