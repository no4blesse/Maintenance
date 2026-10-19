<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Core\Config;

/** Real LINE Messaging API push transport (https://api.line.me/v2/bot/message/push). */
final class LineTransport implements Transport
{
    public function __construct(private string $accessToken)
    {
    }

    public function push(string $to, array $messages, string $retryKey): DeliveryResult
    {
        $body = (string) json_encode(['to' => $to, 'messages' => array_slice($messages, 0, 5)], JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken,
            'X-Line-Retry-Key: ' . $retryKey,
        ];
        $endpoint = (string) Config::get('line.push_endpoint');
        $timeout = (int) Config::get('line.timeout_seconds', 10);

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                return new DeliveryResult(false, null, 'Network error: ' . $err, true, 'LINE');
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
                'timeout' => $timeout, 'ignore_errors' => true,
            ]]);
            $resp = @file_get_contents($endpoint, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }
            if ($resp === false) {
                return new DeliveryResult(false, null, 'Network error', true, 'LINE');
            }
        }
        // 409 = same X-Line-Retry-Key already accepted -> treat as delivered
        $ok = ($status >= 200 && $status < 300) || $status === 409;
        $retryable = !$ok && ($status === 429 || $status >= 500 || $status === 0);
        return new DeliveryResult($ok, $status, mb_substr((string) $resp, 0, 900), $retryable, 'LINE');
    }

    public function name(): string
    {
        return 'LINE';
    }
}
