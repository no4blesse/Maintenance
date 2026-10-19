<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Concurrency-safe sequence generator.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID(expr): the row
 * lock taken by the upsert serialises concurrent callers, and LAST_INSERT_ID
 * is connection-local, so two requests can never receive the same number.
 * (MAX()+1 is never used.)
 */
final class NumberSequence
{
    public static function next(string $key, int $hotelId, int $period = 0): int
    {
        Database::execute(
            'INSERT INTO number_sequences (seq_key, hotel_id, period, last_number) VALUES (?, ?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$key, $hotelId, $period]
        );
        return (int) Database::value('SELECT LAST_INSERT_ID()');
    }

    /** e.g. NT-2026-000001 */
    public static function ticketNo(string $hotelCode, int $hotelId, int $year): string
    {
        $n = self::next('ticket', $hotelId, $year);
        return sprintf('%s-%04d-%06d', $hotelCode, $year, $n);
    }
}
