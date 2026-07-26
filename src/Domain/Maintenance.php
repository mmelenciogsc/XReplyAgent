<?php

declare(strict_types=1);

namespace XReplyAgent\Domain;

use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Settings;

final class Maintenance
{
    /**
     * @return array<string, int>
     */
    public static function cleanup(): array
    {
        $defaults = Settings::defaults();
        $days = (int) get_option(Settings::OPTION_RETENTION_DAYS, $defaults[Settings::OPTION_RETENTION_DAYS] ?? '365');
        return Store::cleanup(max(1, $days));
    }
}
