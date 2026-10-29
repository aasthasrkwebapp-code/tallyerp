# Phase 10l: GST cess and reverse charge

## What it does

### Cess
- Each stock item can have a **cess rate** (%) in its master. It fills in the invoice line, where it can be changed.
- Cess is worked out on the line's taxable value. It is not charged when the invoice has no GST (an unregistered company or an export without tax).
- The invoice shows a Cess column and a Cess total. The print and PDF show them too.
- Ledgers are created when first needed, under Duties & Taxes:
  - **Output Cess** for sales
  - **Input Cess** for purchases
  - If a tax ledger with the same name already exists, it is used instead.

### Reverse charge
- A sales or purchase invoice can be marked **Tax payable on reverse charge**. This option only appears when GST is enabled.
- **Purchase under reverse charge**:
  - The supplier's bill is only the taxable value.
  - The buyer pays the tax, so CGST/SGST/IGST and cess are debited to the input ledgers.
  - The same tax is credited to "CGST/SGST/IGST/Cess Payable (Reverse Charge)".
  - The supplier is credited only the taxable value.
  - The tax is still worked out for an unregistered supplier.
- **Sale under reverse charge**:
  - The bill shows the tax for information, but it is not part of the total.
  - No output tax is posted.
- The invoice screen, print and PDF show the reverse charge badge and a note that the tax is not in the total.

### GST returns
- **GSTR-1**:
  - B2B and credit/debit note rows show the reverse charge flag (Y/N).
  - B2B, CDN, B2CS and HSN show cess.
- **GSTR-3B**:
  - 3.1(a) taxable supplies with cess.
  - 3.1(d) inward supplies under reverse charge.
  - 4(A)(3) ITC on reverse charge and 4(A)(5) other ITC.
  - Reverse charge tax is always paid in cash. Credit can't be used for it.
  - Cess credit is only set off against cess.
- **Purchase register**: shows an RCM badge and a Cess column. The CSV export has both.

## Data

Migration `2026_10_28_000900_add_cess_and_reverse_charge`:
- invoices: `reverse_charge`, `cess_total`
- invoice_items: `cess_rate`, `cess_amount`
- stock_items: `cess_rate`

## Permissions

No new permissions. Invoices use `sales.*` / `purchases.*`, the stock item master uses `inventory.*`, and returns use `gst.view` / `gst.export`.

## Tests

`tests/Feature/Gst/CessReverseChargeTest.php` (6 tests) covers:
- cess from the stock item and its posting
- validation of the cess rate
- reverse charge purchase postings, including from an unregistered supplier
- a reverse charge sale with no output tax
- GSTR-3B sections and the cash-only set-off
- the screens and CSV columns

## Manual checklist

1. Set a cess rate of 12% on a stock item.
2. Make a sales invoice with that item. The Cess % fills in, and the cess total and Output Cess ledger appear in the voucher.
3. Make a purchase from an unregistered supplier and tick reverse charge.
   - The grand total is only the taxable value.
   - The voucher debits input tax and credits the reverse charge payable ledgers.
4. Open GSTR-3B.
   - 3.1(d) shows the purchase.
   - 4(A)(3) shows its credit.
   - The reverse charge liability is in the cash column.
5. The purchase register shows the RCM badge. Export the CSV and check the Reverse charge and Cess columns.
6. At 375 px, the invoice form and GSTR-3B have no sideways scroll.

## Done / partial / missing

- Done:
  - ad valorem cess on items and invoices
  - reverse charge on sales and purchases, with postings and GSTR-1/3B/purchase register reporting
- Missing:
  - specific-rate cess (per unit)
  - reverse charge on expense (non-stock) vouchers
  - the self-invoice and payment voucher that are required for reverse charge purchases from unregistered suppliers
