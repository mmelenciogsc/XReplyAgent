<?php

declare(strict_types=1);

namespace XReplyAgent\Domain;

use XReplyAgent\Support\Settings;

final class RateLimiter
{
    /**
     * @return array{allowed: bool, limit: int, used: int, remaining: int}
     */
    public static function consume(string $scope, int $user_id = 0, string $session_key = ''): array
    {
        $limit = max(1, (int) get_option(Settings::OPTION_DAILY_LIMIT, Settings::defaults()[Settings::OPTION_DAILY_LIMIT]));
        $identity = $user_id > 0 ? 'user:' . $user_id : 'guest:' . self::guestToken($session_key);
        $bucket = 'xra_rl_' . sanitize_key($scope) . '_' . gmdate('Ymd') . '_' . md5($identity);
        $used = (int) get_transient($bucket);
        if ($used >= $limit) {
            return [
                'allowed' => false,
                'limit' => $limit,
                'used' => $used,
                'remaining' => 0,
            ];
        }

        $used++;
        set_transient($bucket, $used, DAY_IN_SECONDS + HOUR_IN_SECONDS);

        return [
            'allowed' => true,
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
        ];
    }

    private static function guestToken(string $session_key): string
    {
        $session_key = trim($session_key);
        if ($session_key !== '') {
            return $session_key;
        }

        $remote_addr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return md5($remote_addr . '|' . $user_agent);
    }
}
