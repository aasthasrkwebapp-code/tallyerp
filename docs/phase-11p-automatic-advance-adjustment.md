# 11p. Automatic adjustment of order advances

Advances against sales and purchase orders (10u) were set off against the
order's invoices by hand, from the order page. Now this happens when the
invoice is posted.

## How it works

When a sales invoice or purchase bill that has lines from an order is
**first posted**, the open advances of that order are adjusted against it:

- oldest advance first, each up to what is still open on it
- in total up to what is pending on the invoice; anything left stays open on
  the advance, or on the invoice
- each adjustment is the same journal as a manual one (Agst Ref on the
  advance bill and on the invoice bill), dated the invoice date, or the
  advance date if that is later
- an invoice with lines from several orders takes each order's advances

This covers invoices posted from the invoice form, a draft or pending invoice
posted later (from the invoice or, for a pending one, the voucher screen),
invoices imported from CSV/Excel, and recurring invoices.
Altering an invoice that is already posted does not adjust again.

After saving, the message names the advances adjusted, for example
"Advance ADV-SO/0001-1 (1,000.00) adjusted against it." If the adjustment
fails (for example the Journal voucher type was removed), the invoice still
posts, the message says why, and the advance can be adjusted by hand as
before.

Parties that are not bill-wise have nothing to adjust: the advance already
reduces their balance.

## Setting

**Company Settings > Preferences > Adjust order advances automatically** is
on by default. Turn it off to keep adjusting by hand.

An automatic adjustment is cancelled from the order page like a manual one.

## Tests

`tests/Feature/Billing/OrderTest.php`,
`test_advances_are_adjusted_when_the_invoice_is_posted`:

- two advances (1,000 and 1,300) against a 2,360 order are adjusted in order
  when the invoice is posted, leaving 60 pending on the invoice
- the journal is dated the invoice date
- altering the posted invoice does not adjust again
- a draft purchase bill is adjusted only when it is posted, and the message
  says so

The two existing advance tests turn the setting off to test manual adjustment.
Approving a pending invoice from the voucher screen uses the same service but
has no automated test; check it by hand (checklist step 6).

## Manual checklist

1. Make a sales order and record an advance against it.
2. Make an invoice from the order and post it. The message names the
   advance, and the order page shows it adjusted.
3. Check the customer's outstanding: the advance and the invoice are set off.
4. Turn the setting off, repeat, and check the advance stays open for a
   manual adjustment.
5. Repeat with a purchase order and a draft bill posted later.
6. With voucher approval required, enter an invoice against an order as a
   non-approver, then approve its voucher from the voucher screen. The
   message names the advance adjusted.

## Done, partial, missing

- **Done:** automatic set-off on first posting, from every posting path;
  setting; message.
- **Missing:** choosing which advance to use when there are several (the
  oldest goes first; adjust by hand to choose).
