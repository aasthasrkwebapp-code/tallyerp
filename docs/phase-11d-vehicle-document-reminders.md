# 11d Vehicle document and licence expiry reminders

## What it does

- **Transport → Vehicle Documents** (`/transport/documents`): insurance, fitness certificate,
  permit, national permit, PUC, road tax, registration and other documents per vehicle, with
  number, issuer, valid from / till, amount paid and notes. Driving licences come from each
  driver's licence expiry.
- Each row shows days left and a status: **Expired** (past the valid-till date), **Due soon**
  (within the reminder window, 30 days by default) or **Valid**. Filters by status and vehicle;
  CSV, Excel and PDF export.
- **Renew** pre-fills a new document starting the day after the old one ends. For each vehicle
  and document type only the latest-running document counts, so the old one drops out of the
  reminders but stays in the vehicle's history.
- **Dashboard** alert when anything is expired or due (for users with `transport.view`).
- **Trips**: saving a trip whose start date is after a vehicle document or the driver's licence
  expired shows a warning (the trip is still saved).
- **E-mail reminders**: `erp:document-reminders`, scheduled daily at 07:00, e-mails the company
  owner once when a document comes within the window and once when it expires (logged in
  `document_reminders`, keyed by document and expiry date, so a renewal starts afresh).
  The window and the e-mail can be changed on the screen (`transport.edit`).
- Inactive vehicles and drivers are left out.

## Permissions

- View `transport.view`; add `transport.create`; edit and reminder settings `transport.edit`;
  delete `transport.delete`. Vehicles must belong to the current company.

## Files

- Migration `2026_10_28_002300_create_vehicle_documents_tables.php`; models `VehicleDocument`,
  `DocumentReminder`; both tables in `config/erp.php` deletion and backup.
- `App\Services\Transport\DocumentExpiry`, `VehicleDocumentController`,
  `App\Notifications\DocumentExpiryReminder`, `SendDocumentReminders` command.
- View `transport/documents.blade.php`; *Documents* button on the vehicles list.
- Tests: `tests/Feature/Transport/VehicleDocumentTest.php` (3).

## Manual checklist

1. Add an insurance policy for a vehicle that ended last week, and a PUC due in two weeks.
2. The dashboard shows "1 expired and 2 due for renewal" (with a driver's licence due).
3. Create a trip for that vehicle: a warning names the expired insurance.
4. Click *Renew* on the insurance, enter the new valid-till date and save: it disappears from
   *Expired or due* and both policies show in the vehicle's history.
5. Run `php artisan erp:document-reminders` with mail set to `log`: one e-mail is logged; a
   second run sends nothing.
6. Change the window to 60 days: more documents show as due.
7. As a viewer the list opens without the forms.

## Done / partial / missing

- Done: documents, statuses, renewal history, dashboard alert, trip warning, e-mail reminders,
  exports, audit, tests.
- Missing: SMS / WhatsApp reminders, reminders to members other than the owner, attaching scanned copies.
