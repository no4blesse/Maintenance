<?php

declare(strict_types=1);

namespace App\Services\Line;

interface Transport
{
    /**
     * @param string $to LINE groupId / userId
     * @param list<array<string, mixed>> $messages LINE message objects
     * @param string $retryKey UUID for X-Line-Retry-Key (idempotent retries)
     */
    public function push(string $to, array $messages, string $retryKey): DeliveryResult;

    public function name(): string;
}
