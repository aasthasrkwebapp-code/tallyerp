# 11z. Configurable keyboard shortcuts

Tally users move around with the keyboard. The app had fixed Alt + key
shortcuts, and the help listed Alt + D, V, L, T and B, but nothing on the
pages answered to those keys. Page shortcuts now work, and each user can
choose their own.

## How it works

- **Alt + key** (Option + key on a Mac) opens a page from anywhere in the
  company. The physical key is used, so Option + key works on a Mac even
  though it types a symbol.
- **The defaults**, until a user changes them:

  | Key | Page |
  | --- | --- |
  | D | Dashboard |
  | V | New voucher |
  | L | Ledgers |
  | T | Trial balance |
  | B | Day book |

- **Choosing keys.** Profile → **Keyboard shortcuts** lists 14 pages:
  - Dashboard, New voucher, Ledgers, Trial balance, Day book
  - Profit & loss, Balance sheet, Outstanding
  - New sales invoice, Sales invoices, New purchase bill
  - Stock items, Stock summary, GSTR-3B

  Give each page a letter or digit, or leave it blank for none.
  - **Back to defaults** clears the user's choice.
  - Shortcuts belong to the user, not the company, so they are the same in
    every company.
- **Refused:**
  - more than one character, or anything but a letter or digit
  - the same key for two pages
  - S, A, C and E, which stay with buttons on the page itself: save the
    form, add a line or item, create new on lists, and alter a document
- **Buttons never clash.** Buttons on a page only use S, A, C and E, which
  pages can't be given.
- **Permissions.** Only pages the user's role can open in the current company
  get a shortcut. For example, a viewer's Alt + V does nothing.
- **The help.** The Keyboard shortcuts box (user menu) lists the user's own
  keys and the page-level keys, with a link to change them.

## Data

Migration `2026_10_29_000100_add_shortcuts_to_users` adds `users.shortcuts`
(JSON, key => page; null means the defaults).

## Files

- `App\Support\Shortcuts`: the pages, defaults and reserved keys; the user's
  shortcuts filtered by permission; checking a choice
- `ProfileController::shortcuts`, route `PUT /profile/shortcuts`
- the Keyboard shortcuts section on `profile/edit.blade.php`
- the layout: the help box, and the page map passed to `public/js/erp.js` as
  JSON
- the view composer in `AppServiceProvider`

## Tests

`tests/Feature/ShortcutTest.php`:

- `test_defaults_are_in_the_layout_and_help`: the default map and help, and
  a viewer losing New voucher
- `test_users_choose_their_own_keys`:
  - saving letters and digits (upper case is folded)
  - the map, the badge, and another user keeping the defaults
  - back to defaults
- `test_bad_keys_are_refused`: reserved keys, two characters, symbols,
  clashes, and the message on the profile page

## Manual checklist

1. On the dashboard press Alt + T. The trial balance opens.
2. Profile → Keyboard shortcuts: give Profit & loss the key P and clear Day
   book. Save.
3. Alt + P opens Profit & loss, and Alt + B does nothing. The help box shows
   the new keys.
4. Try to give Ledgers the key S. It is refused.
5. On a voucher form, Alt + S still saves and Alt + A still adds a line.
6. Back to defaults restores D, V, L, T and B.
7. At 375 px, the profile section has no sideways scroll.

## Not done

- Page-level keys (save, add line, create, alter) are fixed.
- There are no two-key sequences or function-key (F1–F12) shortcuts like
  Tally's.
