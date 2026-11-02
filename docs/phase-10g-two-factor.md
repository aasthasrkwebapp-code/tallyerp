# Phase 10g: two-factor sign-in

## What it does

- **Profile → Two-factor sign-in → Set up.** You confirm your password first.
  1. Scan the QR code with an authenticator app such as Google Authenticator, Microsoft Authenticator or Authy, or type the key.
  2. Enter the 6-digit code to turn it on.
- **Recovery codes**: 8 one-time codes are shown once, when you turn it on. They are stored hashed.
  - Use one to sign in if the phone is lost.
  - **Make new recovery codes** replaces them all.
- **Signing in**:
  - With two-factor sign-in on, the password step does not sign you in. It opens the code page, which is valid for 5 minutes.
  - "Remember me" carries through to the code step.
  - Signing in with a recovery code shows how many are left.
- **Turning it off** needs your password.
- **Company → Settings → Require two-factor sign-in.** Members without it are sent to the set-up page before they can open the company.
  - You can only switch this on after setting up two-factor sign-in for your own account, so you cannot lock yourself out.
- **Lost phone, no recovery codes:** an owner or admin can reset a member's two-factor sign-in from Users & Roles, after entering their own password.
  - The reset is refused when the member also uses a company that this owner or admin does not manage, because the sign-in is shared across companies.
  - The reset is logged as `two_factor_reset`.
- **Audit trail** events:
  - `two_factor_enabled`, `two_factor_disabled` and `two_factor_codes` (codes replaced)
  - `two_factor_failed` (wrong code) and `two_factor_recovery_used`
  - `login`, which is recorded only after the code is accepted

## Security details

- Codes follow RFC 6238 (TOTP): HMAC-SHA1, 6 digits, a new code every 30 seconds, with one step of clock drift allowed either way. The tests check the RFC test vectors.
- Each code works once. The last time step used is stored, and older or equal steps are refused.
- Wrong codes are limited to 5 attempts per user and IP. After that the user waits about a minute.
- The secret is encrypted with `APP_KEY`, and recovery codes are bcrypt hashes. Neither is ever sent in JSON, because both are hidden attributes.
- A wrong password never reaches the code step. It is still logged as `login_failed`.
- The QR code is drawn on the server as inline SVG (bacon/bacon-qr-code), so the secret never goes to a third party.

## Data

- `users` gains `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` and `two_factor_last_step`.
- Migration: `2026_10_28_000600_add_two_factor_to_users`. It rolls back cleanly.
- New package: `bacon/bacon-qr-code` ^3.0, for PHP 8.1 and later. It needs ext-iconv, which is already in the deployment checklist.

## Tests

`tests/Feature/Auth/TwoFactorTest.php` (10 tests) covers:
- the RFC vectors
- set-up and confirmation, and the secret being stored encrypted
- the password re-check before set-up
- sign-in with a code, wrong codes, and refusing a reused code
- recovery codes working once
- the challenge expiring and the rate limit
- a wrong password not reaching the code step
- turning it off
- a company requiring it
- an owner resetting a member's two-factor sign-in, and the limits on that reset

## Manual checklist

1. Profile → Two-factor sign-in → Set up. Scan the code with your phone, enter the code, and save the recovery codes.
2. Log out and log in. The code page appears, and the code from the app signs you in.
3. Log out and log in again with a recovery code. It works once and you are told how many are left.
4. Company settings → Require two-factor sign-in. A member without it is taken to the set-up page.
5. At 375 px, the set-up page, the codes page and the code step all fit the screen.

## Done / partial / missing

- Done: authenticator-app two-factor sign-in, recovery codes, company-wide enforcement, owner reset, audit, and rate limiting.
- Missing:
  - Codes by SMS or email.
  - Trusting a device for 30 days.
  - Restricting sign-in by IP address.
