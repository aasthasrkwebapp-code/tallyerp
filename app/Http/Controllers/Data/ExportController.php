<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Data\ExportService;
use App\Services\Data\ImportService;
use App\Services\Reports\AccountBalances;
use App\Support\CsvExport;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    /** Export type => permission needed to see that data. */
    public const VIEW = ['ledgers' => 'masters.view', 'customers' => 'sales.view', 'suppliers' => 'purchases.view', 'stock_items' => 'inventory.view', 'vouchers' => 'vouchers.view'];

    public function index(Request $request, AccountBalances $balances)
    {
        [$from, $to] = $balances->defaultPeriod();

        return view('data.export', [
            'types' => array_filter(ImportService::TYPES, fn ($t, $k) => $request->user()->can(self::VIEW[$k]), ARRAY_FILTER_USE_BOTH),
            'from' => $from, 'to' => $to,
        ]);
    }

    public function download(Request $request, ExportService $exports, string $type)
    {
        abort_unless(isset(self::VIEW[$type]), 404);
        abort_unless($request->user()->can(self::VIEW[$type]), 403);
        $data = $request->validate(['format' => ['nullable', 'in:csv,xlsx'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        [$headers, $rows] = $exports->export($type, $data['from'] ?? null, $data['to'] ?? null);
        AuditLog::record('data_exported', null, null, ['type' => $type, 'format' => $data['format'] ?? 'csv'], 'Exported '.strtolower(ImportService::TYPES[$type]['label']));

        $name = "{$type}-".now()->format('Ymd');

        return ($data['format'] ?? 'csv') === 'xlsx'
            ? CsvExport::xlsx("{$name}.xlsx", $headers, $rows, ImportService::TYPES[$type]['label'])
            : CsvExport::download("{$name}.csv", $headers, $rows);
    }
}
