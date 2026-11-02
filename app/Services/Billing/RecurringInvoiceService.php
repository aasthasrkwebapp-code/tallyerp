<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRun;
use App\Models\RecurringVoucher;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring sales and purchase invoices.
 *
 * A template is made from an existing invoice: its party, lines, addresses, terms and
 * narration are kept; its date, number and links to orders, notes and serial numbers
 * are not. Each due date makes an ordinary invoice through InvoiceService, so GST,
 * stock and bill-wise entries work as for one entered by hand. A date that cannot be
 * saved (locked period, inactive ledger, not enough stock) is logged as failed and stays
 * due, so it is retried on the next run.
 */
class RecurringInvoiceService
{
    /** Most invoices one template makes in a single run (catching up a long gap). */
    public const MAX_PER_RUN = 36;

    public function __construct(protected InvoiceService $invoices) {}

    /** The template of an invoice, and the reason it can't be one (or null). */
    public function templateFrom(Invoice $invoice): array
    {
        if (! in_array($invoice->kind, RecurringInvoice::KINDS, true)) {
            return [null, 'Only sales and purchase invoices can recur.'];
        }
        if ($invoice->status() === Voucher::CANCELLED) {
            return [null, 'A cancelled invoice cannot be made recurring.'];
        }
        $input = $this->invoices->toInput($invoice);
        if (collect($input['items'])->contains(fn ($i) => trim((string) $i['serials']) !== '')) {
            return [null, 'Lines with serial numbers cannot repeat, since each serial is sold once. Remove the serials or enter these invoices by hand.'];
        }
        unset($input['voucher_number'], $input['date'], $input['due_date'], $input['original_invoice_id'], $input['reference_date']);
        $input['items'] = array_map(fn ($i) => array_diff_key($i, array_flip(['order_item_id', 'note_item_id', 'serials', 'mfg_date', 'expiry_date'])), $input['items']);

        return [$input, null];
    }

    /**
     * @param  array{name: string, frequency: string, start_date: string, end_date?: ?string, due_days?: ?int, mode: string, is_active?: bool, next_date?: ?string}  $data
     */
    public function save(?RecurringInvoice $recurring, ?Invoice $source, array $data, User $user): RecurringInvoice
    {
        $errors = [];
        if (! array_key_exists($data['frequency'] ?? '', RecurringVoucher::FREQUENCIES)) {
            $errors['frequency'] = 'Select how often the invoice repeats.';
        }
        if (! empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            $errors['end_date'] = 'The end date cannot be before the start date.';
        }
        $template = $recurring?->template;
        if ($source) {
            [$template, $why] = $this->templateFrom($source);
            if ($why) {
                $errors['invoice'] = $why;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $kind = $source?->kind ?? $recurring->kind;
        if ($source) {
            // Checked as a real invoice would be, then rolled back.
            $this->dryRun($kind, $template, $data, $user);
        }

        return DB::transaction(function () use ($recurring, $source, $data, $user, $template, $kind) {
            $attributes = [
                'name' => trim($data['name']),
                'frequency' => $data['frequency'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'due_days' => isset($data['due_days']) && $data['due_days'] !== '' ? max(0, (int) $data['due_days']) : null,
                'mode' => ($data['mode'] ?? 'post') === 'draft' ? 'draft' : 'post',
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];
            if ($source) {
                $attributes += ['kind' => $kind, 'party_account_id' => $template['party_account_id'], 'source_invoice_id' => $source->id,
                    'template' => $template, 'total' => $source->grand_total];
            }
            if (! $recurring) {
                $attributes['next_date'] = $data['start_date'];
                $attributes['created_by'] = $user->id;
            } elseif (! empty($data['next_date'])) {
                $attributes['next_date'] = $data['next_date'];
            }
            $next = $attributes['next_date'] ?? $recurring?->next_date?->toDateString();
            if (! empty($attributes['end_date']) && $next && $next > $attributes['end_date']) {
                $attributes['next_date'] = null;
            }

            $recurring ??= new RecurringInvoice;
            $recurring->fill($attributes)->save();

            return $recurring;
        });
    }

    /**
     * Make every invoice due on or before $asOf.
     *
     * @return Collection<int, RecurringInvoiceRun>
     */
    public function run(RecurringInvoice $recurring, User $user, ?string $asOf = null): Collection
    {
        $asOf ??= now()->toDateString();
        $runs = collect();

        while ($recurring->isDue($asOf) && $runs->count() < self::MAX_PER_RUN) {
            $date = $recurring->next_date->toDateString();
            if ($recurring->end_date && $date > $recurring->end_date->toDateString()) {
                $recurring->updateQuietly(['next_date' => null]);
                break;
            }
            try {
                $invoice = $this->invoices->save(null, $recurring->kind, $this->input($recurring, $date), $user, $recurring->mode === 'draft' ? 'draft' : 'post');
            } catch (ValidationException $e) {
                $runs->push(RecurringInvoiceRun::create([
                    'company_id' => $recurring->company_id, 'recurring_invoice_id' => $recurring->id, 'date' => $date,
                    'status' => 'failed', 'message' => collect($e->errors())->flatten()->unique()->implode(' '), 'run_by' => $user->id,
                ]));
                $recurring->updateQuietly(['last_run_at' => now()]);
                break;
            }

            $runs->push(RecurringInvoiceRun::create([
                'company_id' => $recurring->company_id, 'recurring_invoice_id' => $recurring->id, 'date' => $date,
                'invoice_id' => $invoice->id, 'status' => 'created', 'run_by' => $user->id,
            ]));
            AuditLog::record('generated', $invoice, null, ['recurring_invoice_id' => $recurring->id, 'date' => $date],
                "Made from recurring invoice \"{$recurring->name}\" for {$date}");

            $next = $recurring->dateAfter($date)->toDateString();
            $recurring->updateQuietly([
                'next_date' => $recurring->end_date && $next > $recurring->end_date->toDateString() ? null : $next,
                'last_run_at' => now(),
            ]);
        }

        return $runs;
    }

    /**
     * Run every active template of the current company that is due.
     *
     * @return array{created: int, failed: int}
     */
    public function runDue(User $fallbackUser, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $created = $failed = 0;
        $due = RecurringInvoice::query()->where('is_active', true)->whereNotNull('next_date')->where('next_date', '<=', $asOf)->with('creator')->orderBy('id')->get();
        foreach ($due as $recurring) {
            // Invoices are made in the name of the person who set up the schedule, with their rights.
            $user = $recurring->creator && $recurring->creator->companies()->whereKey($recurring->company_id)->wherePivot('is_active', true)->exists()
                ? $recurring->creator : $fallbackUser;
            $runs = $this->run($recurring, $user, $asOf);
            $created += $runs->where('status', 'created')->count();
            $failed += $runs->where('status', 'failed')->count();
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** The invoice input for one date. Without a due-days setting the party's credit period applies. */
    public function input(RecurringInvoice $recurring, string $date): array
    {
        return $recurring->template + [
            'date' => $date,
            'due_date' => $recurring->due_days !== null ? Carbon::parse($date)->addDays((int) $recurring->due_days)->toDateString() : null,
        ];
    }

    protected function dryRun(string $kind, array $template, array $data, User $user): void
    {
        $probe = new RecurringInvoice(['kind' => $kind, 'template' => $template, 'due_days' => $data['due_days'] ?? null]);
        DB::beginTransaction();
        try {
            $this->invoices->save(null, $kind, $this->input($probe, now()->toDateString()), $user, 'draft');
        } catch (ValidationException $e) {
            // The date is checked when each invoice is made.
            $messages = collect($e->errors())->reject(fn ($m, $k) => $k === 'date')->all();
            if ($messages !== []) {
                throw ValidationException::withMessages(['invoice' => collect($messages)->flatten()->unique()->implode(' ')]);
            }
        } finally {
            DB::rollBack();
        }
    }
}
