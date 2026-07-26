<?php

declare(strict_types=1);

namespace XReplyAgent\AI;

interface ProviderInterface
{
    public function name(): string;

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function complete(array $request): array;
}
