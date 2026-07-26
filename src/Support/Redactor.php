<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class Redactor
{
    /**
     * @param mixed $value
     * @return mixed
     */
    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = self::cleanWithKey((string) $key, $item);
            }
            return $clean;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function cleanWithKey(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return self::clean($value);
        }

        if (is_string($value)) {
            if (self::isSensitiveKey($key)) {
                return '[redacted]';
            }

            return self::redactString($value);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        return str_contains($key, 'api_key')
            || str_contains($key, 'apikey')
            || str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'password')
            || str_contains($key, 'authorization')
            || str_contains($key, 'auth');
    }

    private static function redactString(string $value): string
    {
        $patterns = [
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
            '/\bBearer\s+[A-Za-z0-9._-]+\b/i',
            '/\bsk-[A-Za-z0-9]{8,}\b/i',
            '/\b(?:\+?\d[\d\-\s().]{7,}\d)\b/',
        ];

        $replacements = [
            '[redacted-email]',
            'Bearer [redacted]',
            '[redacted-key]',
            '[redacted-phone]',
        ];

        return preg_replace($patterns, $replacements, $value) ?? $value;
    }
}
