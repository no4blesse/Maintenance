<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้')
    {
        parent::__construct(403, $message);
    }
}
