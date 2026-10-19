<?php

declare(strict_types=1);

namespace App\Services\Line;

/**
 * Local/testing transport. NEVER contacts LINE. Records the attempt only.
 * Used when credentials are missing or LINE_TRANSPORT=mock.
 */
final class MockTransport implements Transport
{
    /** @var list<array{to:string, messages:array, retryKey:string}> captured pushes (tests) */
    public static array $sent = [];
    public static bool $failNext = false;
    public static int $failStatus = 500;

    public function push(string $to, array $messages, string $retryKey): DeliveryResult
    {
        if (self::$failNext) {
            self::$failNext = false;
            return new DeliveryResult(false, self::$failStatus, 'MOCK simulated failure', self::$failStatus >= 500 || self::$failStatus === 429, 'MOCK');
        }
        self::$sent[] = ['to' => $to, 'messages' => $messages, 'retryKey' => $retryKey];
        return new DeliveryResult(true, 200, 'MOCK: not sent to LINE (integration NOT CONNECTED)', false, 'MOCK');
    }

    public function name(): string
    {
        return 'MOCK';
    }
}
