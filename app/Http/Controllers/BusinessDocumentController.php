<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Support\Books;
use App\Support\GstStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Estimates (and later orders and challans): no postings, convertible into an invoice.
 */
class BusinessDocumentController extends Controller
{
    public function index(Request $request, Company $company, string $type)
    {
        $this->authorizeCompany($company);
        $this->checkType($type);

        $documents = $company->businessDocuments()
            ->where('type', $type)
            ->with('party')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('documents.index', compact('company', 'type', 'documents'));
    }

    public function create(Request $request, Company $company, string $type)
    {
        $this->authorizeCompany($company);
        $this->checkType($type);

        return view('documents.form', ['company' => $company, 'type' => $type, 'document' => new BusinessDocument(['type' => $type])] + $this->choices($company, $type));
    }

    public function store(Request $request, Company $company, string $type)
    {
        $this->authorizeCompany($company);
        $this->checkType($type);

        [$data, $lines] = $this->validated($request, $company, $type);

        $document = DB::transaction(function () use ($company, $type, $data, $lines) {
            $document = $company->businessDocuments()->create($data + [
                'type' => $type,
                'number' => BusinessDocument::nextNumber($company, $type, $data['date']),
                'status' => 'open',
            ]);
            $document->lines()->createMany($lines);

            return $document;
        });

        return redirect()
            ->route('companies.documents.show', [$company, $type, $document])
            ->with('success', $document->typeLabel().' #'.$document->number.' saved.');
    }

    public function show(Company $company, string $type, BusinessDocument $document)
    {
        $this->authorizeCompany($company);
        $this->checkDocument($company, $type, $document);

        $document->load('lines.account', 'lines.stockItem.unit', 'party', 'voucher');

        return view('documents.show', compact('company', 'type', 'document'));
    }

    public function edit(Company $company, string $type, BusinessDocument $document)
    {
        $this->authorizeCompany($company);
        $this->checkDocument($company, $type, $document);

        if ($document->status !== 'open') {
            return back()->with('error', 'Only open documents can be edited.');
        }

        $document->load('lines');

        return view('documents.form', compact('company', 'type', 'document') + $this->choices($company, $type));
    }

    public function update(Request $request, Company $company, string $type, BusinessDocument $document)
    {
        $this->authorizeCompany($company);
        $this->checkDocument($company, $type, $document);
        abort_unless($document->status === 'open', 403);

        [$data, $lines] = $this->validated($request, $company, $type);

        DB::transaction(function () use ($document, $data, $lines) {
            // A new financial year means the next number in that year's series.
            if (\App\Models\Voucher::fyStartYear($data['date']) !== (int) $document->fy_start_year) {
                $document->number = BusinessDocument::nextNumber($document->company, $document->type, $data['date']);
            }
            $document->update($data);
            $document->lines()->delete();
            $document->lines()->createMany($lines);
        });

        return redirect()
            ->route('companies.documents.show', [$company, $type, $document])
            ->with('success', $document->typeLabel().' #'.$document->number.' updated.');
    }

    /**
     * Cancel (open) or delete (cancelled) a document.
     */
    public function destroy(Company $company, string $type, BusinessDocument $document)
    {
        $this->authorizeCompany($company);
        $this->checkDocument($company, $type, $document);

        if ($document->status === 'open') {
            $document->update(['status' => 'cancelled']);

            return back()->with('success', $document->typeLabel().' #'.$document->number.' cancelled.');
        }

        if ($document->status === 'cancelled') {
            $document->delete();

            return redirect()->route('companies.documents.index', [$company, $type])->with('success', 'Deleted.');
        }

        return back()->with('error', 'A converted document is kept for reference.');
    }

    /**
     * Open the matching GST invoice form, pre-filled from this document.
     */
    public function convert(Company $company, string $type, BusinessDocument $document)
    {
        $this->authorizeCompany($company);
        $this->checkDocument($company, $type, $document);

        if ($document->status !== 'open' || ! $document->convertsTo()) {
            return back()->with('error', 'Only open documents can be converted.');
        }

        return redirect()->route('companies.gst-invoices.create', [$company, 'type' => $document->convertsTo(), 'from_document' => $document->id]);
    }

    protected function choices(Company $company, string $type): array
    {
        $books = new Books($company);
        $side = BusinessDocument::TYPES[$type][1];
        $accounts = $company->accounts()->where('is_active', true)->with('group')->orderBy('name')->get();

        return [
            'parties' => $accounts->filter(fn (Account $a) => in_array($books->primaryGroup($a->account_group_id)->code, ['CURRENT_ASSETS', 'CURRENT_LIABILITIES'], true)
                && ! preg_match('/^(GST|TDS|PAY)_/', (string) $a->code))->values(),
            'items' => $accounts->filter(fn (Account $a) => in_array($books->primaryGroup($a->account_group_id)->code, GstInvoiceController::LINE_GROUPS[$side], true))->values(),
            'stockItems' => $company->stockItems()->with('unit')->orderBy('name')->get(),
            'rates' => GstInvoiceController::RATES,
            'states' => GstStates::STATES,
        ];
    }

    /**
     * @return array{0: array, 1: array<int, array>}
     */
    protected function validated(Request $request, Company $company, string $type): array
    {
        $inCompany = fn () => Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:date'],
            'party_account_id' => ['required', $inCompany()],
            'place_of_supply' => ['nullable', Rule::in(array_keys(GstStates::STATES))],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', $inCompany()],
            'lines.*.stock_item_id' => ['nullable', Rule::exists('stock_items', 'id')->where('company_id', $company->id)],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'lines.*.quantity' => ['nullable', 'required_with:lines.*.stock_item_id', 'numeric', 'gt:0'],
            'lines.*.item_rate' => ['nullable', 'required_with:lines.*.stock_item_id', 'numeric', 'min:0'],
            'lines.*.taxable' => ['nullable', 'required_without:lines.*.stock_item_id', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', Rule::in(GstInvoiceController::RATES)],
        ]);

        $lines = collect($data['lines'])->map(function (array $l) {
            $stock = ! empty($l['stock_item_id']);
            $taxable = $stock ? round((float) $l['quantity'] * (float) $l['item_rate'], 2) : round((float) $l['taxable'], 2);

            return [
                'account_id' => (int) $l['account_id'],
                'stock_item_id' => $stock ? (int) $l['stock_item_id'] : null,
                'description' => $l['description'] ?? null,
                'hsn_sac' => $l['hsn_sac'] ?? null,
                'quantity' => $stock ? round((float) $l['quantity'], 3) : null,
                'item_rate' => $stock ? round((float) $l['item_rate'], 2) : null,
                'taxable' => $taxable,
                'rate' => (float) $l['rate'],
                'tax' => round($taxable * (float) $l['rate'] / 100, 2),
            ];
        })->values()->all();

        unset($data['lines']);
        $data['amount'] = round(array_sum(array_map(fn ($l) => $l['taxable'] + $l['tax'], $lines)), 2);

        return [$data, $lines];
    }

    protected function checkType(string $type): void
    {
        abort_unless(isset(BusinessDocument::TYPES[$type]), 404);
    }

    protected function checkDocument(Company $company, string $type, BusinessDocument $document): void
    {
        abort_unless((int) $document->company_id === (int) $company->id && $document->type === $type, 404);
    }
}
