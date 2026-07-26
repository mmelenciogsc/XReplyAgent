<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class Similarity
{
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function score(string $left, string $right): float
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $percent);

        return max(0.0, min(100.0, (float) $percent));
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    public static function distinctReplies(array $candidates, int $desired = 3): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $reply = (string) ($candidate['reply_text'] ?? '');
            if ($reply === '') {
                continue;
            }

            $duplicate = false;
            foreach ($unique as $seen) {
                if (self::score($reply, (string) ($seen['reply_text'] ?? '')) >= 82.0) {
                    $duplicate = true;
                    break;
                }
            }

            if (!$duplicate) {
                $unique[] = $candidate;
            }

            if (count($unique) >= $desired) {
                break;
            }
        }

        return array_values($unique);
    }
}
