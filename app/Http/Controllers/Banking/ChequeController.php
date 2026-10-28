<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ChequeBook;
use App\Models\SpoiltCheque;
use App\Models\Voucher;
use App\Services\Banking\ChequeRegister;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\NumberToWords;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cheque register, cheque books, spoilt leaves and cheque printing.
 */
class ChequeController extends Controller
{
    public function __construct(protected ChequeRegister $register) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'bank' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(array_keys(ChequeRegister::STATUSES))],
            'direction' => ['nullable', 'in:issued,received'],
            'type' => ['nullable', Rule::in(array_keys(Voucher::INSTRUMENTS))],
            'q' => ['nullable', 'string', 'max:50'],
        ]);
        $rows = $this->register->rows($filters);

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');

            return CsvExport::download('cheque-register-'.now()->toDateString().'.csv',
                ['Date', 'Instrument', 'Number', 'Issued / received', 'Bank', 'Party', 'Amount', 'Status', 'Cleared on', 'Voucher', 'Note'],
                $rows->map(fn ($r) => [
                    $r->date, Voucher::INSTRUMENTS[$r->type] ?? $r->type, $r->number, ucfirst($r->direction), $r->bank?->name, $r->party,
                    Money::normalize($r->amount), ChequeRegister::STATUSES[$r->status], $r->bank_date?->toDateString() ?? '',
                    $r->voucher?->displayNumber() ?? '', $r->reason ?? '',
                ]));
        }

        return view('banking.cheques.index', [
            'rows' => $rows,
            'filters' => $filters,
            'banks' => $this->banks(),
            'totals' => $rows->groupBy('status')->map(fn ($g) => ['count' => $g->count(), 'amount' => $g->sum('amount')]),
        ]);
    }

    public function books()
    {
        $books = ChequeBook::query()->with('account')->orderBy('account_id')->orderBy('from_number')->get();

        return view('banking.cheques.books', [
            'books' => $books,
            'usage' => $books->mapWithKeys(fn ($b) => [$b->id => $this->register->usage($b)]),
            'banks' => $this->banks(),
        ]);
    }

    public function storeBook(Request $request)
    {
        $data = $this->bookInput($request, null);
        $book = ChequeBook::create($data);

        return redirect()->route('cheque-books.index')->with('success', "Cheque book {$book->name} added ({$book->leaves()} leaves).");
    }

    public function editBook(ChequeBook $chequeBook)
    {
        return view('banking.cheques.book-form', ['book' => $chequeBook, 'banks' => $this->banks(), 'layout' => $chequeBook->printLayout()]);
    }

    public function updateBook(Request $request, ChequeBook $chequeBook)
    {
        $data = $this->bookInput($request, $chequeBook);
        $layout = $request->validate([
            'layout' => ['array'],
            'layout.*' => ['nullable', 'numeric', 'min:0', 'max:300'],
        ])['layout'] ?? [];
        $data['layout'] = collect(ChequeBook::DEFAULT_LAYOUT)->except('ac_payee')
            ->map(fn ($v, $k) => isset($layout[$k]) && $layout[$k] !== '' ? (float) $layout[$k] : $v)
            ->put('ac_payee', $request->boolean('ac_payee'))->all();
        $chequeBook->update($data);

        return redirect()->route('cheque-books.index')->with('success', "Cheque book {$chequeBook->name} saved.");
    }

    public function destroyBook(ChequeBook $chequeBook)
    {
        if ($this->register->usage($chequeBook)['used']->isNotEmpty()) {
            return back()->with('error', 'Cheques from this book are on vouchers, so it cannot be deleted. Mark it inactive instead.');
        }
        $chequeBook->delete();

        return redirect()->route('cheque-books.index')->with('success', 'Cheque book deleted.');
    }

    /** Mark a leaf as spoilt (torn, lost, written wrongly) so it is never used. */
    public function spoil(Request $request, ChequeBook $chequeBook)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $number = str_pad(ltrim(trim($data['number']), '0') ?: '0', 6, '0', STR_PAD_LEFT);
        $usage = $this->register->usage($chequeBook);
        if (! $chequeBook->contains($number)) {
            return back()->withErrors(['number' => "Leaf {$number} is not in this book."])->withInput();
        }
        if ($usage['used']->has((int) $number)) {
            return back()->withErrors(['number' => "Leaf {$number} is on voucher {$usage['used'][(int) $number]->displayNumber()}. Cancel that voucher first."])->withInput();
        }
        if ($usage['spoilt']->has((int) $number)) {
            return back()->withErrors(['number' => "Leaf {$number} is already marked spoilt."])->withInput();
        }
        SpoiltCheque::create(['cheque_book_id' => $chequeBook->id, 'number' => $number, 'date' => $data['date'], 'reason' => $data['reason'], 'created_by' => $request->user()->id]);

        return back()->with('success', "Leaf {$number} marked spoilt.");
    }

    public function unspoil(SpoiltCheque $spoiltCheque)
    {
        $spoiltCheque->delete();

        return back()->with('success', "Leaf {$spoiltCheque->number} is usable again.");
    }

    /** The cheque laid out for printing on the bank's leaf. */
    public function print(Voucher $voucher)
    {
        abort_unless($voucher->instrument_type === 'cheque' && $voucher->instrument_direction === 'issued' && ! $voucher->isCancelled(), 404);
        $voucher->load('instrumentAccount', 'entries');
        $book = ChequeBook::query()->where('account_id', $voucher->instrument_account_id)->get()->first(fn ($b) => $b->contains((string) $voucher->instrument_number));
        $entry = $voucher->entries->first(fn ($e) => $e->account_id === $voucher->instrument_account_id && (float) $e->credit > 0);
        $paise = Money::toPaise($entry?->credit ?? $voucher->total);
        AuditLog::record('cheque_printed', $voucher, null, null, "Cheque {$voucher->instrument_number} printed");

        return view('banking.cheques.print', [
            'voucher' => $voucher,
            'layout' => $book?->printLayout() ?? ChequeBook::DEFAULT_LAYOUT,
            'amount' => Money::format(Money::fromPaise($paise)),
            'words' => preg_replace('/^(Indian )?Rupees /', '', NumberToWords::rupees($paise)),
            'date' => ($voucher->instrument_date ?? $voucher->date)->format('dmY'),
        ]);
    }

    // ------------------------------------------------------------------

    protected function banks()
    {
        return Account::query()->where('account_type', 'bank')->orderBy('name')->get(['id', 'name']);
    }

    protected function bookInput(Request $request, ?ChequeBook $book): array
    {
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_type', 'bank')->where('company_id', app(\App\Support\CurrentCompany::class)->id())],
            'name' => ['nullable', 'string', 'max:100'],
            'from_number' => ['required', 'integer', 'min:0', 'max:999999999'],
            'to_number' => ['required', 'integer', 'gte:from_number', 'max:999999999'],
            'digits' => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_active' => ['nullable', 'boolean'],
        ], ['to_number.gte' => 'The last leaf cannot be before the first.', 'account_id.exists' => 'Choose a bank ledger.']);
        if ($data['to_number'] - $data['from_number'] >= 1000) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to_number' => 'A cheque book can have at most 1000 leaves.']);
        }
        $overlap = ChequeBook::query()->where('account_id', $data['account_id'])
            ->when($book, fn ($q) => $q->whereKeyNot($book->id))
            ->where('from_number', '<=', $data['to_number'])->where('to_number', '>=', $data['from_number'])->first();
        if ($overlap) {
            throw \Illuminate\Validation\ValidationException::withMessages(['from_number' => "These leaves overlap cheque book {$overlap->name}."]);
        }
        $data['digits'] = $data['digits'] ?? 6;
        $data['is_active'] = $book ? $request->boolean('is_active') : true;
        $data['name'] = trim((string) ($data['name'] ?? '')) ?: str_pad((string) $data['from_number'], $data['digits'], '0', STR_PAD_LEFT).'–'.str_pad((string) $data['to_number'], $data['digits'], '0', STR_PAD_LEFT);

        return $data;
    }
}
