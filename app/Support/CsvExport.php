<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExport
{
    /**
     * Stream a CSV download. Cells that start with = + - @ are prefixed with
     * a quote so spreadsheet programs do not execute them as formulas.
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map([static::class, 'safe'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function safe(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '@', "\t", "\r"], true)) {
            return "'".$value;
        }
        // Allow plain negative numbers, guard other leading minus signs.
        if ($value !== '' && $value[0] === '-' && ! is_numeric(str_replace(',', '', $value))) {
            return "'".$value;
        }

        return $value;
    }
}
