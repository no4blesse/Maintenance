<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'ไม่พบข้อมูลที่ต้องการ')
    {
        parent::__construct(404, $message);
    }
}
