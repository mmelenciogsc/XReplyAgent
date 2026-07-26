<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        if (!is_readable($template)) {
            return;
        }

        extract($data, EXTR_SKIP);
        include $template;
    }

    public static function partial(string $relative, array $data = []): void
    {
        self::render(XRA_DIR . ltrim($relative, '/'), $data);
    }
}
