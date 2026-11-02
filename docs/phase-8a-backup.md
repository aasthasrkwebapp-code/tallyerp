# Phase 8a — Backup, restore and integrity checks

Menu **Data**: Backup & Restore (permission `data.backup`, owner and admin), Integrity Check
(`data.view`, also accountants and viewers).

## Backups (`App\Services\Data\BackupService`)

* **Back up now** writes one zip per company to `storage/app/private/backups/{company id}/`
  (change with `ERP_BACKUP_PATH`). It holds `manifest.json` (format, company profile and
  settings, installed migrations, row count and SHA-256 per table) and one JSON file per table:
  groups, ledgers, cost centres, financial years and periods, voucher types and numbering,
  vouchers, entries, bill allocations, revisions, parties, invoices, stock, payroll, transport,
  bank statement lines, plus the company logo.
* Users, roles, memberships and the audit trail are **not** in a company backup.
* Every backup is listed in `backup_logs` with its size, checksum and record counts, and is
  verified straight after it is made. Creating, downloading, restoring and deleting are audited.

## Verify

Checks that the file exists and its checksum is unchanged, the manifest is a supported format,
it is this company's backup and not from a newer version of the application, each table file
matches its checksum and row count, and every posted voucher in it balances.

## Restore

1. Choose **Restore…** on a backup and type the company name.
2. The backup is verified again; a failed check stops the restore.
3. A **safety backup** of the current data is made (type "Before restore").
4. In one database transaction the company's books are removed and the backup is inserted with
   its original ids; numbering sequences come back too, so voucher numbers continue from the
   backup. Users that no longer exist are cleared from "created by" columns.
5. Every reference between restored rows must stay inside the company (a crafted file pointing at
   another company's ledgers is rolled back). The company profile, settings and logo are put back.

Upload a backup zip to restore a downloaded copy; it is verified on upload.

## Schedule

Automatic backups (off / daily / weekly) and how many to keep are set on the same screen.
`php artisan erp:backup --scheduled` runs daily at 01:30 from the Laravel scheduler (cron:
`* * * * * php artisan schedule:run`). It backs up companies that are due, verifies each backup
and removes scheduled backups beyond the number kept. `php artisan erp:backup [ids]` backs up on
demand.

## Integrity check (`App\Services\Data\IntegrityChecker`)

Reads the tables directly: posted vouchers balance and have at least two lines, voucher totals
match their lines, invoice totals match their items and their voucher, bill allocations equal
their entries, no record uses another company's ledgers, voucher numbers are unique, opening
balances agree (warning), and stock is not negative today (warning). Failing vouchers and
invoices are listed with links.

## Manual test checklist

1. Data → Backup & Restore → **Back up now**: a row appears, "Verified".
2. Download it; the zip contains `manifest.json` and `tables/*.json`.
3. Enter a new voucher, rename a ledger.
4. Restore the backup typing the company name → the voucher is gone, the ledger name is back, a
   "Before restore" backup is at the top. A wrong name is refused.
5. Upload the downloaded zip → "uploaded and verified". Upload a text file → refused.
6. Set the schedule to daily, keep 2; run `php artisan erp:backup --scheduled` on several days
   (or change the server date) → only two scheduled backups remain.
7. Integrity Check: all green on a clean company.
8. Log in as an accountant: Integrity Check opens; Backup & Restore is forbidden.
9. Phone width: rows become cards, no sideways scrolling.

## Completed / partial / missing

* **Done**: company backup with checksums, verification, restore with safety backup and
  rollback, upload, download, delete, scheduled backups with retention, backup log, integrity
  checks, permissions, audit, tests.
* **Partial**: backups are built in memory (fine for tens of thousands of vouchers; very large
  companies need a streamed export); restore needs the same or a newer application version.
* **Missing**: copying backups off the server (S3, Google Drive), encrypted backups, whole-database
  backup from the screen (use `mysqldump`), restore into a different or new company.

Tests: `tests/Feature/Data/BackupTest.php` (7 tests).
