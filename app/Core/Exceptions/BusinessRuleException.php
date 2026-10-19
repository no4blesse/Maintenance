<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/** Thrown when a domain/business rule blocks an action (shown to user). */
final class BusinessRuleException extends RuntimeException
{
}
