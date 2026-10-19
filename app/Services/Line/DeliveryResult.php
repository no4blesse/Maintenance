<?php

declare(strict_types=1);

namespace App\Services\Line;

/** Result of one delivery attempt */
final class DeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $httpStatus,
        public readonly string $excerpt,
        public readonly bool $retryable,
        public readonly string $transport,
    ) {
    }
}
