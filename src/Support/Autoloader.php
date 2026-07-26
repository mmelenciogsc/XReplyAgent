<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class Autoloader
{
    public static function register(string $prefix, string $baseDir): void
    {
        spl_autoload_register(
            static function (string $class) use ($prefix, $baseDir): void {
                if (strpos($class, $prefix) !== 0) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
                $file = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative . '.php';
                if (is_readable($file)) {
                    require_once $file;
                }
            }
        );
    }
}
