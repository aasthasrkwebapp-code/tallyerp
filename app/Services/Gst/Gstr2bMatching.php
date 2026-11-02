<?php

namespace App\Services\Gst;

use App\Models\Gstr2bReturn;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * GSTR-2B against the purchase books.
 *
 * The portal's GSTR-2B JSON lists the B2B invoices (b2b) and credit / debit
 * notes (cdnr) the suppliers reported. Each is looked up among the posted
 * purchase bills and debit notes by supplier GSTIN and document number
 * (ignoring case, punctuation and leading zeros of each number), then compared on date,
 * taxable value and tax. A supplier's invoice or debit note is a purchase
 * bill in the books; a supplier's credit note is a debit note.
 *
 * Results: matched; mismatch (same number, different date or amounts);
 * probable (number differs, but GSTIN, date and amounts agree); in 2B only
 * (not entered in the books); in books only (bills of the month from
 * registered suppliers that the suppliers have not reported).
 */
class Gstr2bMatching
{
    public const STATUSES = [
        'matched' => 'Matched',
        'mismatch' => 'Mismatch',
        'probable' => 'Probable match',
        'missing_in_books' => 'In 2B, not in books',
        'missing_in_2b' => 'In books, not in 2B',
    ];

    public const TAX = ['taxable', 'igst', 'cgst', 'sgst', 'cess'];

    /**
     * Reads the portal's JSON.
     *
     * @return array{gstin:string, period:string, generated:?string, documents: list<array>}
     */
    public function parse(string $json): array
    {
        $decoded = json_decode($json, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
        if (! is_array($data) || ! isset($data['gstin'], $data['rtnprd']) || ! is_array($data['docdata'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'This is not a GSTR-2B JSON file from the GST portal (gstin, rtnprd and docdata are missing).']);
        }
        if (! preg_match('/^(0[1-9]|1[0-2])(\d{4})$/', (string) $data['rtnprd'], $m)) {
            throw ValidationException::withMessages(['file' => 'The return period in the file is not valid.']);
        }

        $documents = [];
        foreach ($data['docdata']['b2b'] ?? [] as $supplier) {
            foreach ($supplier['inv'] ?? [] as $doc) {
                $documents[] = $this->document($supplier, $doc, 'invoice', (string) ($doc['inum'] ?? ''));
            }
        }
        foreach ($data['docdata']['cdnr'] ?? [] as $supplier) {
            foreach ($supplier['nt'] ?? [] as $doc) {
                $documents[] = $this->document($supplier, $doc, strtoupper((string) ($doc['typ'] ?? 'C')) === 'D' ? 'debit_note' : 'credit_note', (string) ($doc['ntnum'] ?? ''));
            }
        }

        return [
            'gstin' => strtoupper((string) $data['gstin']), 'period' => "{$m[2]}-{$m[1]}",
            'generated' => isset($data['gendt']) ? (string) $data['gendt'] : null, 'documents' => $documents,
        ];
    }

    /** Matches a stored statement against the books. */
    public function match(Gstr2bReturn $return): array
    {
        $parsed = $this->parse($return->data);
        $start = Carbon::createFromFormat('Y-m-d', $return->period.'-01')->startOfDay();
        $gstins = array_values(array_unique(array_column($parsed['documents'], 'gstin')));

        $books = Invoice::query()->whereIn('kind', ['purchase', 'debit_note'])
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
            ->whereNotNull('party_gstin')
            ->where(fn ($q) => $q->whereIn('party_gstin', $gstins)->orWhereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]))
            ->with('voucher')->get()
            ->map(fn (Invoice $i) => [
                'invoice' => $i, 'gstin' => strtoupper($i->party_gstin), 'kind' => $i->kind, 'number' => (string) $i->reference, 'key' => $this->key((string) $i->reference),
                'date' => $i->date->toDateString(), 'taxable' => Money::toPaise($i->taxable_total), 'igst' => Money::toPaise($i->igst_total),
                'cgst' => Money::toPaise($i->cgst_total), 'sgst' => Money::toPaise($i->sgst_total), 'cess' => Money::toPaise($i->cess_total),
            ])->keyBy(fn ($b) => $b['invoice']->id);
        $tolerance = 100; // ₹1 on each amount, for rounding

        $rows = [];
        $used = [];
        $pending = [];
        // First by number, then the rest by date and amounts.
        foreach ($parsed['documents'] as $doc) {
            $book = $books->first(fn ($b) => ! isset($used[$b['invoice']->id]) && $b['gstin'] === $doc['gstin'] && $b['kind'] === $doc['book_kind'] && $b['key'] !== '' && $b['key'] === $doc['key']);
            if (! $book) {
                $pending[] = $doc;

                continue;
            }
            $used[$book['invoice']->id] = true;
            $differences = $this->differences($doc, $book, $tolerance);
            $rows[] = $this->row($differences ? 'mismatch' : 'matched', $doc, $book, $differences);
        }
        foreach ($pending as $doc) {
            $book = $books->first(fn ($b) => ! isset($used[$b['invoice']->id]) && $b['gstin'] === $doc['gstin'] && $b['kind'] === $doc['book_kind'] && $b['date'] === $doc['date']
                && $this->differences($doc, $b, $tolerance, false) === []);
            if ($book) {
                $used[$book['invoice']->id] = true;
                $rows[] = $this->row('probable', $doc, $book, ['number' => "Number {$doc['number']} in 2B, {$book['number']} in books"]);
            } else {
                $rows[] = $this->row('missing_in_books', $doc, null, []);
            }
        }
        foreach ($books as $book) {
            if (! isset($used[$book['invoice']->id]) && $book['date'] >= $start->toDateString() && $book['date'] <= $start->copy()->endOfMonth()->toDateString()
                && $book['igst'] + $book['cgst'] + $book['sgst'] + $book['cess'] > 0) {
                $rows[] = $this->row('missing_in_2b', null, $book, []);
            }
        }

        $order = array_flip(array_keys(self::STATUSES));
        usort($rows, fn ($a, $b) => [$order[$a['status']], $a['party'], $a['date']] <=> [$order[$b['status']], $b['party'], $b['date']]);
        $summary = [];
        foreach (self::STATUSES as $status => $label) {
            $of = array_filter($rows, fn ($r) => $r['status'] === $status);
            $summary[$status] = [
                'count' => count($of),
                'tax_2b' => array_sum(array_map(fn ($r) => $r['two_b'] ? $this->tax($r['two_b']) : 0, $of)),
                'tax_books' => array_sum(array_map(fn ($r) => $r['books'] ? $this->tax($r['books']) : 0, $of)),
            ];
        }

        return ['parsed' => $parsed, 'rows' => collect($rows), 'summary' => $summary,
            'itc_2b' => array_sum(array_map(fn ($d) => $d['itc_available'] ? $this->signedTax($d) : 0, $parsed['documents'])),
            'itc_unavailable' => array_sum(array_map(fn ($d) => $d['itc_available'] ? 0 : $this->signedTax($d), $parsed['documents']))];
    }

    /** Document numbers compared without case, punctuation or leading zeros (SS/0101 = ss-101). */
    public function key(string $number): string
    {
        return preg_replace('/(?<![0-9])0+(?=[0-9])|(?<![0-9])0+$/', '', preg_replace('/[^A-Z0-9]/', '', strtoupper($number)));
    }

    protected function document(array $supplier, array $doc, string $type, string $number): array
    {
        $items = $doc['items'] ?? $doc['itms'] ?? [];
        $sum = fn (string $field) => array_key_exists($field, $doc)
            ? Money::toPaise($doc[$field])
            : array_sum(array_map(fn ($i) => Money::toPaise($i[$field] ?? ($i['itm_det'][$field] ?? 0)), $items));
        $date = \DateTime::createFromFormat('!d-m-Y', (string) ($doc['dt'] ?? ''));

        return [
            'type' => $type, 'book_kind' => $type === 'credit_note' ? 'debit_note' : 'purchase',
            'gstin' => strtoupper((string) ($supplier['ctin'] ?? '')), 'trade_name' => (string) ($supplier['trdnm'] ?? ''),
            'number' => $number, 'key' => $this->key($number), 'date' => $date ? $date->format('Y-m-d') : '',
            'value' => Money::toPaise($doc['val'] ?? 0),
            'taxable' => $sum('txval'), 'igst' => $sum('igst'), 'cgst' => $sum('cgst'), 'sgst' => $sum('sgst'), 'cess' => $sum('cess'),
            'reverse_charge' => strtoupper((string) ($doc['rev'] ?? 'N')) === 'Y',
            'itc_available' => strtoupper((string) ($doc['itcavl'] ?? 'Y')) !== 'N',
            'reason' => (string) ($doc['rsn'] ?? ''),
        ];
    }

    /** @return array<string,string> field => explanation */
    protected function differences(array $doc, array $book, int $tolerance, bool $withDate = true): array
    {
        $out = [];
        if ($withDate && $doc['date'] !== $book['date']) {
            $out['date'] = 'Date '.Carbon::parse($doc['date'])->format('d-m-Y').' in 2B, '.Carbon::parse($book['date'])->format('d-m-Y').' in books';
        }
        foreach (self::TAX as $field) {
            if (abs($doc[$field] - $book[$field]) > $tolerance) {
                $out[$field] = ($field === 'taxable' ? 'Taxable value' : strtoupper($field)).' '.Money::format($doc[$field], paiseInput: true).' in 2B, '.Money::format($book[$field], paiseInput: true).' in books';
            }
        }

        return $out;
    }

    protected function row(string $status, ?array $doc, ?array $book, array $differences): array
    {
        return [
            'status' => $status, 'two_b' => $doc, 'books' => $book, 'differences' => $differences,
            'gstin' => $doc['gstin'] ?? $book['gstin'], 'party' => $book['invoice']->party_name ?? $doc['trade_name'],
            'number' => $doc['number'] ?? $book['number'], 'date' => $doc['date'] ?? $book['date'],
        ];
    }

    protected function tax(array $amounts): int
    {
        return $amounts['igst'] + $amounts['cgst'] + $amounts['sgst'] + $amounts['cess'];
    }

    /** A supplier's credit note reduces the credit. */
    protected function signedTax(array $doc): int
    {
        return $doc['type'] === 'credit_note' ? -$this->tax($doc) : $this->tax($doc);
    }
}
