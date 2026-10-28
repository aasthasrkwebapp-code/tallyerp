# 11q. Search as you type

Global search (10d) needed Enter to show results. The search box in the top
bar, and the box on the search page (used on phones), now show matches while
you type.

## How it works

- After two letters, and a short pause (0.2 s), a dropdown opens under the
  box with up to five matches from each group: ledgers, stock items, batches
  and serials, invoices and notes, vouchers, orders, delivery and receipt
  notes, employees and trips.
- Each match shows its title and a line of detail (date, party, amount, group).
- **All results for "…"** at the bottom opens the full search page.
- Keys:
  - **↓ / ↑** move through the matches
  - **Enter** opens the highlighted match; with nothing highlighted it runs
    the full search, as before
  - **Escape** closes the dropdown
  - **/** or **Ctrl+K** still jump to the box
- Groups follow the user's permissions, exactly as on the search page,
  because the dropdown reads the same search endpoint as JSON.
- A slow answer for an older term is ignored, so the list always matches
  what is in the box.
- The box is marked up as a combobox with a listbox, so screen readers
  announce the highlighted match.

No new tables, routes or permissions.

## Tests

`tests/Feature/Search/GlobalSearchTest.php`,
`test_json_response_and_topbar_box`:

- the top bar and the search page boxes carry the dropdown hook
- the JSON has the fields the dropdown reads

The typing, arrow keys, Enter, Escape and the phone layout were checked in a
browser (Chromium) at 1366 px and 375 px.

## Manual checklist

1. Press `/` and type two letters of a party name. The dropdown lists the
   ledger and its invoices.
2. Press ↓ then Enter: the ledger opens.
3. Type a term and press Enter without moving: the full results page opens.
4. On a phone, open Search and type: the dropdown fits the screen.
5. Sign in as a viewer without payroll access and check employees are not
   listed.

## Done, partial, missing

- **Done:** dropdown with keyboard use on the top bar and the search page.
- **Missing:** configurable shortcuts.
