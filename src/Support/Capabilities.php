<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class Capabilities
{
    public const USE_APP = 'xra_use_app';
    public const REVIEW_REPLIES = 'xra_review_replies';
    public const MANAGE_PERSONAS = 'xra_manage_personas';
    public const VIEW_ANALYTICS = 'xra_view_analytics';
    public const MANAGE_SETTINGS = 'xra_manage_settings';

    public static function install(): void
    {
        $roles = [
            'administrator' => self::all(),
            'editor' => self::editorCaps(),
            'xra_administrator' => self::all(),
            'xra_reviewer' => self::reviewerCaps(),
            'xra_viewer' => [self::USE_APP, self::VIEW_ANALYTICS],
        ];

        foreach ($roles as $role_name => $caps) {
            $role = get_role($role_name);
            if ($role === null && str_starts_with($role_name, 'xra_')) {
                $role = add_role($role_name, ucwords(str_replace('_', ' ', $role_name)), ['read' => true]);
            }

            if ($role === null) {
                continue;
            }

            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }

        update_option('xra_capabilities_version', XRA_VERSION, false);
    }

    public static function sync(): void
    {
        if ((string) get_option('xra_capabilities_version', '') !== XRA_VERSION) {
            self::install();
        }
    }

    public static function all(): array
    {
        return [
            self::USE_APP,
            self::REVIEW_REPLIES,
            self::MANAGE_PERSONAS,
            self::VIEW_ANALYTICS,
            self::MANAGE_SETTINGS,
        ];
    }

    public static function editorCaps(): array
    {
        return [
            self::USE_APP,
            self::REVIEW_REPLIES,
            self::VIEW_ANALYTICS,
        ];
    }

    public static function reviewerCaps(): array
    {
        return [
            self::USE_APP,
            self::REVIEW_REPLIES,
            self::VIEW_ANALYTICS,
        ];
    }

    public static function canUseApp(): bool
    {
        return current_user_can(self::USE_APP) || current_user_can('manage_options');
    }

    public static function canReviewReplies(): bool
    {
        return current_user_can(self::REVIEW_REPLIES) || self::canManageSettings();
    }

    public static function canManagePersonas(): bool
    {
        return current_user_can(self::MANAGE_PERSONAS) || self::canManageSettings();
    }

    public static function canViewAnalytics(): bool
    {
        return current_user_can(self::VIEW_ANALYTICS) || self::canManageSettings() || self::canReviewReplies();
    }

    public static function canManageSettings(): bool
    {
        return current_user_can(self::MANAGE_SETTINGS) || current_user_can('manage_options');
    }

    public static function canRecordMetrics(): bool
    {
        return self::canReviewReplies() || self::canManageSettings();
    }

    public static function canAccessAdminShell(): bool
    {
        return self::canReviewReplies() || self::canManagePersonas() || self::canViewAnalytics() || self::canManageSettings();
    }
}
