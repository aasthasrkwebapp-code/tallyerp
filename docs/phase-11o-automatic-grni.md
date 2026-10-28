# 11o. Automatic month-end GRNI provision

The GRNI provision (10s) books the value of goods received but not yet billed
at a period end. Until now someone had to post it by hand each month. A
company can now have it posted automatically.

## Setting

**Inventory > GRNI Provision** has an **Automatic month-end provision** box
(changing it needs `purchases.create`):

- **Post on the 1st for the last day of the month before**: on or off (off by
  default).
- **Reverse it the next day**: on by default, as with the manual provision.

## What runs

`php artisan erp:grni-provisions` runs every night at 00:50 from the
scheduler. For each company with the setting on (and inventory enabled):

1. The date is the last day of the month before today.
2. If a provision already exists for that date, it does nothing. This
   includes a provision cancelled by hand: it is not posted again.
3. If the date is outside the company's financial years, it does nothing.
4. Otherwise it posts the provision as the company's owner, exactly like the
   manual one. The provision is marked **Automatic** in the list, and the
   audit log says so.
5. If nothing is unbilled, nothing is posted.
6. If posting fails, for example because the period is locked, the reason
   is kept. It is tried again each night, so it posts once the lock is lifted
   or a missing ledger is fixed.

The box shows the last run, its date and its result. A failure is shown in red.

Options: `erp:grni-provisions 3 5` runs only companies 3 and 5, and
`--date=2026-06-01` treats that date as today.

## Tests

`tests/Feature/Inventory/GrniProvisionTest.php`,
`test_automatic_month_end_provision`:

- off by default
- the setting is saved
- the provision is posted for 31 May on 1 June, with its reversal, marked
  automatic, as the owner
- later runs in June do nothing
- a cancelled provision is not posted again
- nothing is posted when everything is billed
- a locked July fails, is shown on the page, and posts after the lock is lifted
- a viewer cannot change the setting

## Manual checklist

1. Turn on the automatic provision on the GRNI page and save.
2. Make a goods receipt note dated last month and leave it unbilled.
3. Run `php artisan erp:grni-provisions`. The provision for last month's end
   appears marked Automatic, with its reversal on the 1st.
4. Run it again: nothing more is posted.
5. Make a GRN this month, lock this month's period, and run the command with
   `--date` set to the 1st of next month. The box shows the failure in red.
   Unlock the period and run it again: the provision is posted.
6. Check the page at phone width.

## Done, partial, missing

- **Done:** setting, nightly command and schedule, skip and retry rules,
  last-run result, Automatic badge, audit.
- **Missing:** an e-mail when the run fails, and quarter-end-only runs.
