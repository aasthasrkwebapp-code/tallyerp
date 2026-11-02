# 11y. IP restrictions

A company's books can now be limited to the office network or VPN. Members
connecting from any other address are refused, even with the right password
and second factor.

## Setting it up

Company settings → **Security** (needs `company.edit`):

- **Allowed IP addresses**: single addresses (`203.0.113.7`) or ranges in
  CIDR form (`203.0.113.0/24`, `2001:db8::/32`), IPv4 or IPv6. Separate them
  with new lines, commas or spaces. Up to 50 entries.
  - Leave it empty to allow any address, which is how every company starts.
  - Anything that is not an address or range is refused and named.
  - The page shows how many entries are set and your own address now.
- **Let owners in from any address**: on by default. It keeps the company
  reachable if the office address changes. Turn it off to hold owners to the
  list as well.
- **Lock-out guard.** Saving a list that does not include your own address is
  refused, unless you are an owner who is let in from anywhere.
- The change is recorded in the audit trail with the old and new lists.
  Saving the other preferences leaves the list as it is.

## What members see

- Every page inside a company checks the address before anything else is
  shown. A refused member sees a page that:
  - names the company and their address
  - tells them to connect from the office network or VPN, or ask the owner
  - lists their other companies, which they can switch to
  - has a sign-out link
- **The refusal is recorded** in the audit trail as `access_denied`, with the
  user and the address. It is recorded once per address in a session, so
  repeated clicks don't fill the log.
- **Outside the company pages.** The company list, sign-in, profile and
  two-factor pages are not limited, so a member can always switch company or
  sign out.

## Behind a proxy

Behind a load balancer or reverse proxy, the app sees the proxy's address
unless the proxy is trusted. Set `TRUSTED_PROXIES` in `.env` to its address
(comma-separated, or `*` when only the proxy can reach the app).
`docs/deployment.md` lists this.

## Files

- `App\Support\IpAllowList`: parsing, checking entries, and matching
  (Symfony `IpUtils`)
- the check in `EnsureCompanySelected`
- `SettingsController::updateSecurity`, route `PUT /settings/security`
- the Security tab in `settings/edit.blade.php`
- `errors/ip-blocked.blade.php`
- `TRUSTED_PROXIES` in `bootstrap/app.php` and `.env.example`

No migration: the list lives in the company's settings
(`allowed_ips`, `ip_owner_exempt`).

## Tests

`tests/Feature/Security/IpRestrictionTest.php`:

- `test_allow_list_parsing_and_matching`:
  - addresses, IPv4 and IPv6 ranges, duplicates and separators
  - invalid entries and prefix lengths
  - an empty list allows any address
- `test_saving_the_list_guards_against_lock_out`:
  - an invalid entry, a list without your own address, and more than 50
    entries are refused
  - saving, the audit row, and the settings page
  - the list survives a preferences save, and clearing it
  - a viewer is forbidden
- `test_members_outside_the_list_are_refused`:
  - inside and outside addresses
  - every company page is refused, with the refusal audited once
  - switching to another company works
  - owners let in from anywhere, and held to the list when that is off

## Manual checklist

1. Company settings → Security. Note "Your address now".
2. Enter a range that does not include it and turn off "Let owners in from
   any address". Saving is refused.
3. Add your own address and save. Pages still open.
4. Sign in as another member from a different network (or change the list to
   exclude them). They see the refusal page and can switch company. The audit
   trail shows `access_denied`.
5. Clear the list. Everyone can open the company again.
6. At 375 px, the Security tab and the refusal page have no sideways scroll.

## Not done

- There is no IP limit for the whole application; the limit is set per
  company.
- SMS or e-mail sign-in codes are not offered.
