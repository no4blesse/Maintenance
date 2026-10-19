<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Response;

/**
 * CSV export (Excel-compatible, UTF-8 with BOM) with protection against
 * spreadsheet formula injection: cells beginning with = + - @ TAB CR are
 * prefixed with a single quote.
 */
final class Csv
{
    public static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && (preg_match('/^[\x00-\x20]*[=+\-@]/', $s) || in_array($s[0], ["\t", "\r", "\n"], true)) && !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            $s = "'" . $s;
        }
        return $s;
    }

    /**
     * @param list<string> $headers
     * @param iterable<array<int, mixed>> $rows
     */
    public static function response(string $filename, array $headers, iterable $rows): Response
    {
        return Response::stream(static function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map([self::class, 'cell'], $headers), ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, array_map([self::class, 'cell'], array_values($r)), ',', '"', '');
            }
            fclose($out);
        }, 'text/csv; charset=UTF-8', $filename);
    }
}
