<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Services\Data\ImportService;
use App\Support\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Upload → preview (a full dry run) → import. The uploaded file is kept in
 * storage/app/private/imports under a random token until it is imported or
 * replaced, and only the user who uploaded it can use the token.
 */
class ImportController extends Controller
{
    public function __construct(protected ImportService $imports) {}

    public function index(Request $request)
    {
        return view('data.import', ['types' => $this->allowedTypes($request), 'selected' => $request->input('type')]);
    }

    public function template(Request $request, string $type, string $format)
    {
        $this->authorizeType($request, $type);
        $columns = ImportService::columns($type);
        $headers = array_column($columns, 0);
        $example = ImportService::TYPES[$type]['example'];

        return $format === 'xlsx'
            ? CsvExport::xlsx("import-{$type}-template.xlsx", $headers, [$example], ImportService::TYPES[$type]['label'])
            : CsvExport::download("import-{$type}-template.csv", $headers, [$example]);
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ImportService::TYPES))],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls,ods'],
            'skip_duplicates' => ['nullable', 'boolean'],
        ], ['file.mimes' => 'Upload a .csv or .xlsx file.']);
        $this->authorizeType($request, $data['type']);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, ['csv', 'txt', 'xlsx', 'xls', 'ods'], true) ? $extension : 'csv';
        $rows = $this->imports->read($file->getRealPath(), $extension, $data['type']);

        $token = Str::random(32);
        $file->storeAs('imports', "{$token}.{$extension}", 'local');
        $this->forgetPending($request);
        $request->session()->put('import', ['token' => $token, 'type' => $data['type'], 'extension' => $extension, 'name' => $file->getClientOriginalName(), 'skip' => $request->boolean('skip_duplicates')]);

        $result = $this->imports->run($data['type'], $rows, $request->user(), commit: false, skipDuplicates: $request->boolean('skip_duplicates'));

        return view('data.import-preview', ['result' => $result, 'pending' => $request->session()->get('import'), 'label' => ImportService::TYPES[$data['type']]['label'], 'rows' => $rows]);
    }

    public function confirm(Request $request)
    {
        $pending = $this->pending($request);
        $this->authorizeType($request, $pending['type']);
        $rows = $this->imports->read($this->pendingPath($pending), $pending['extension'], $pending['type']);
        $result = $this->imports->run($pending['type'], $rows, $request->user(), commit: true, skipDuplicates: $pending['skip']);

        if ($result['errors']) {
            return view('data.import-preview', ['result' => $result, 'pending' => $pending, 'label' => ImportService::TYPES[$pending['type']]['label'], 'rows' => $rows]);
        }
        $this->forgetPending($request);
        $label = strtolower(ImportService::TYPES[$pending['type']]['label']);

        return redirect()->route('imports.index')->with('success', "Imported {$result['created']} {$label}".($result['skipped'] ? ", skipped {$result['skipped']} that already existed" : '').'.');
    }

    public function errors(Request $request)
    {
        $pending = $this->pending($request);
        $this->authorizeType($request, $pending['type']);
        $rows = $this->imports->read($this->pendingPath($pending), $pending['extension'], $pending['type']);
        $result = $this->imports->run($pending['type'], $rows, $request->user(), commit: false, skipDuplicates: $pending['skip']);

        return CsvExport::download('import-errors.csv', ['Row', 'Problem'], array_map(fn ($e) => [$e['row'], $e['message']], $result['errors']));
    }

    protected function allowedTypes(Request $request): array
    {
        return array_filter(ImportService::TYPES, fn ($t) => $request->user()->can($t['permission']));
    }

    protected function authorizeType(Request $request, string $type): void
    {
        abort_unless(isset(ImportService::TYPES[$type]), 404);
        abort_unless($request->user()->can(ImportService::TYPES[$type]['permission']), 403, 'You do not have permission to create '.strtolower(ImportService::TYPES[$type]['label']).'.');
    }

    protected function pending(Request $request): array
    {
        $pending = $request->session()->get('import');
        if (! $pending || ! is_file($this->pendingPath($pending))) {
            abort(redirect()->route('imports.index')->with('error', 'Upload the file again; the earlier upload has expired.'));
        }

        return $pending;
    }

    protected function pendingPath(array $pending): string
    {
        return storage_path("app/private/imports/{$pending['token']}.{$pending['extension']}");
    }

    protected function forgetPending(Request $request): void
    {
        if ($old = $request->session()->get('import')) {
            @unlink($this->pendingPath($old));
        }
        $request->session()->forget('import');
    }
}
