# Phase 11b: Recurring sales and purchase invoices

## What it does
- Any sales or purchase invoice that is not cancelled has a **Make recurring** button. The form asks for:
  - a name
  - how often it repeats: weekly, monthly, quarterly, half-yearly or yearly
  - the first date, and an optional last date
  - the number of days until each invoice is due. Leave it blank to use the party's credit period.
  - whether each invoice is **posted** (or sent for approval, as the user's rights decide) or saved as a **draft** for review
- The template copies the invoice's:
  - party, lines (item or ledger, description, HSN, quantity, unit, rate, discount, GST and cess), batch
  - place of supply, addresses, reverse charge, narration and terms
- The template does not copy the invoice's:
  - date or number
  - credit/debit note link
  - order and delivery note links
  - manufacturing and expiry dates
- Before the template is saved, it is checked by saving a draft invoice and rolling it back. An inactive ledger or a missing tax setting is caught at once.
- **Lines with serial numbers can't recur**, since each serial is sold once. Credit and debit notes and cancelled invoices can't recur either.
- Each due date makes an ordinary invoice through the invoice service, so GST, stock, the voucher and the bill-wise reference work as for an invoice entered by hand.
  - Monthly and longer schedules keep the first date's day, using the month's last day when the month is shorter (shared with recurring vouchers).
  - A long gap is caught up, up to 36 invoices a run.
- **Failures**: a date that can't be saved (locked period, no financial year, not enough stock) is logged with the reason and stays due, so it is tried again on the next run. The schedule page shows the reason.
- **Scheduler**: `erp:recurring-invoices` runs daily at 00:40, after recurring vouchers. It works company by company, in the name of the person who set up each schedule, or the owner if that person has left. **Recurring Invoices** (Transactions menu) lists every schedule with its next date, mode, amount, status and failures, and has **Run due now**.
- **Alter** changes the name, frequency, dates, due days, mode and next date, and can pause the schedule. To change the lines, make a new recurring invoice from an updated invoice. Deleting a schedule keeps the invoices it made.

## Data
Migration `2026_10_28_002100_create_recurring_invoices_tables` creates:
- `recurring_invoices`: the template as JSON, the schedule, mode and due days
- `recurring_invoice_runs`: date, invoice, status and message

Both are included in backups and in company deletion. The party ledger can't be deleted while a schedule uses it.

## Permissions
They follow the invoice kind.

| Action | Sales | Purchases |
|---|---|---|
| See schedules | `sales.view` | `purchases.view` |
| Make, alter, run | `sales.create` | `purchases.create` |
| Delete | `sales.delete` | `purchases.delete` |

## Tests
`tests/Feature/Billing/RecurringInvoiceTest.php` has 4 tests. They cover:
- catching up three months, with posted invoices, totals, due dates, terms and lines
- the next date, and nothing more being due
- draft mode
- a failed date that stays due with its reason
- the end date stopping the schedule
- credit notes and cancelled invoices refused
- the screens, run now, altering, the console command, viewer rights, and deletion keeping the invoices

## Manual checklist
1. Open a rent invoice and choose Make recurring. Set monthly from the 1st of next month, due in 7 days.
2. Recurring Invoices lists it as Scheduled. On its date it turns Due. Press Run due now, and an invoice is made with the next date moved on.
3. Lock the next month's period and run again. The schedule shows the failure and stays due.
4. At 375 px, the list, the form and the schedule page have no sideways scroll.

## Done / partial / missing
- Done: recurring sales and purchase invoices from an existing invoice, with a scheduler, failure log and catch-up.
- Partial: the lines can only be changed by making a new schedule from another invoice.
- Missing:
  - emailing each invoice to the customer
  - price escalation, such as a yearly rent increase
