<?php

namespace App\Support;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report downloads. Reports call download() with a header row and data rows;
 * ?export=csv gives CSV and ?export=xlsx gives an Excel workbook of the same rows.
 */
class CsvExport
{
    public static function requested(Request $request): bool
    {
        return in_array($request->input('export'), ['csv', 'xlsx', 'pdf'], true);
    }

    /**
     * Stream a CSV download (or XLSX when ?export=xlsx). Cells that start with = + - @ are prefixed
     * with a quote so spreadsheet programs do not execute them as formulas.
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        if (request()->input('export') === 'xlsx') {
            return static::xlsx(preg_replace('/\.csv$/i', '', $filename).'.xlsx', $headers, $rows);
        }
        if (request()->input('export') === 'pdf') {
            return static::pdf(preg_replace('/\.csv$/i', '', $filename).'.pdf', $headers, $rows);
        }

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

    /** Excel workbook: numbers are stored as numbers, everything else as plain text (never formulas). */
    public static function xlsx(string $filename, array $headers, iterable $rows, string $title = 'Report'): StreamedResponse
    {
        $book = static::workbook($headers, $rows, $title);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * The same rows as a printable A4 PDF (landscape when wide), titled from the file name,
     * e.g. "trial-balance-2026-04-01-to-2027-03-31" → "Trial Balance".
     */
    public static function pdf(string $filename, array $headers, iterable $rows, ?string $title = null): StreamedResponse
    {
        $rows = collect($rows)->map(fn ($r) => array_values((array) $r))->all();
        $numeric = array_map(fn ($i) => $rows !== [] && collect($rows)->every(fn ($r) => ! isset($r[$i]) || $r[$i] === '' || $r[$i] === null || is_numeric(str_replace(',', '', (string) $r[$i]))) && collect($rows)->contains(fn ($r) => isset($r[$i]) && $r[$i] !== ''), array_keys(array_values($headers)));
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $title ??= \Illuminate\Support\Str::headline(trim(preg_replace('/-?\d{4}-\d{2}-\d{2}.*$|-\d{8}.*$/', '', $base), '-')) ?: 'Report';
        $from = request()->input('from');
        $to = request()->input('to');
        $period = $from && $to ? \Illuminate\Support\Carbon::parse($from)->format('d M Y').' to '.\Illuminate\Support\Carbon::parse($to)->format('d M Y')
            : ($to ? 'As on '.\Illuminate\Support\Carbon::parse($to)->format('d M Y') : null);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.report', [
            'company' => app(CurrentCompany::class)->get(), 'title' => $title, 'period' => $period,
            'headers' => array_values($headers), 'rows' => $rows, 'numeric' => $numeric,
        ])->setPaper('a4', count($headers) > 6 ? 'landscape' : 'portrait');
        $output = $pdf->output();

        return response()->streamDownload(fn () => print($output), $filename, ['Content-Type' => 'application/pdf']);
    }

    public static function workbook(array $headers, iterable $rows, string $title = 'Report'): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31));

        $r = 1;
        foreach (array_values($headers) as $i => $h) {
            $sheet->setCellValueExplicit([$i + 1, $r], (string) $h, DataType::TYPE_STRING);
        }
        $sheet->getStyle([1, 1, max(count($headers), 1), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($rows as $row) {
            $r++;
            foreach (array_values((array) $row) as $i => $value) {
                if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d{1,13}(\.\d{1,4})?$/', $value) && ! preg_match('/^-?0\d/', $value))) {
                    $sheet->setCellValueExplicit([$i + 1, $r], (float) $value, DataType::TYPE_NUMERIC);
                    if (is_string($value) && str_contains($value, '.')) {
                        $sheet->getStyle([$i + 1, $r])->getNumberFormat()->setFormatCode('#,##0.'.str_repeat('0', strlen(substr(strrchr($value, '.'), 1))));
                    }
                } else {
                    $sheet->setCellValueExplicit([$i + 1, $r], (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        foreach (range(1, max(count($headers), 1)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return $book;
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
