# 11j. Positive pay

Open it from Banking > Positive Pay. Under the CTS Positive Pay System, the
drawer tells the bank the key details of each cheque it has issued. The bank
then checks a cheque against them before paying it. RBI recommends this for
cheques of ₹50,000 and above, and many banks require it for ₹5 lakh and
above.

## How it works

- Choose a bank ledger and a minimum amount (default ₹50,000).
- **Due to report** has two kinds of rows:
  - **Issue:** issued cheques from that bank (cheque instrument, posted)
    that are not yet cleared (pending or post-dated), are at or above the
    minimum, and have not been in a file before.
  - **Cancel:** cheques already sent in a file that have since been
    cancelled or reversed, so the bank can refuse them.
- **Make positive pay file** (needs `banking.create`):
  - It records a file with a copy of each cheque's details: number, date,
    amount, payee, and the action.
  - Each cheque is reported once.
  - The file needs the bank ledger's account number.
- The file downloads as CSV or Excel with these columns: Account Number,
  IFSC, Cheque Number, Cheque Date (DD-MM-YYYY), Amount, Payee Name, Action
  (I = issued, C = cancelled).
- Files made earlier can be downloaded again exactly as they were sent.
  Later edits to the vouchers do not change them.
- Making a file is recorded in the audit log.

Tables: `positive_pay_batches` and `positive_pay_items` (the items keep
`voucher_id`, which is nulled if the voucher is deleted).

## Manual checklist

1. Add the account number and IFSC to a bank ledger. Issue cheques of ₹75,000, ₹40,000 and ₹2,00,000 (post-dated). The list shows the first and third.
2. Make the file and download the CSV. It has two I rows, and the list is now empty.
3. Cancel the ₹2,00,000 voucher. It appears as Cancel. Make a second file: it has one C row.
4. A bank without an account number shows a warning and cannot make a file.
5. As a viewer, the files open, but there is no Make button.

## Done / partial / missing

- **Done:** due list with threshold, issue and cancel actions, files with a copy of what was sent, CSV/Excel, audit, permissions, tests (`tests/Feature/Banking/PositivePayTest.php`).
- **Partial:** the generic column layout. Most banks accept it or map it in their upload screen, but none of their own templates is built in.
- **Missing:** upload to a bank by API (net banking or host-to-host); amendments to reported cheques (cancel and reissue instead).
