<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors, string $message = 'ข้อมูลไม่ถูกต้อง')
    {
        parent::__construct($message, 422);
    }
}
