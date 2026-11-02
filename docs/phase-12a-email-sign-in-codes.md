# 12a. Sign-in codes by e-mail

Two-factor sign-in can now use a 6-digit code e-mailed to the user, as well
as an authenticator app. It is set up from Profile > Two-factor sign-in.

## Setting it up

The set-up page offers two choices: **Set up with an app** or **Use codes
sent to my e-mail**. Both need the password again (password confirmation).

Choosing e-mail sends a code to the user's address. Typing it back turns
e-mail codes on and shows eight recovery codes once, the same as the app
method. **Send a new code** and **use an authenticator app instead** are on the
same page.

To switch method, turn two-factor off and set it up again.

## Signing in

1. After the password, the sign-in page e-mails a code at once. The address
   is shown partly hidden (for example `de**@example.com`).
2. Reloading the page does not send another code while the last one is
   still valid.
3. **Send a new code** works once a minute. Only the newest code works.
4. A recovery code still works if the user cannot reach their e-mail.

The usual limit of five wrong codes, and the five-minute limit on the sign-in
step, apply as they do for app codes.

## Rules for codes

- A code works once and expires after 10 minutes
  (`User::EMAIL_CODE_MINUTES`).
- Another code can be sent after 60 seconds
  (`User::EMAIL_CODE_RESEND_SECONDS`). The resend route is also throttled to
  6 requests a minute.
- Codes are stored hashed and are never shown in the app.
- The e-mail says what the code is for, how long it lasts, and what to do if
  the user did not just try to sign in.
- A company setting that requires two-factor sign-in accepts e-mail codes.
- An owner's **Reset 2FA** on Members clears e-mail codes too, and puts the
  method back to the app default.

Turning on, turning off, failed codes, recovery code use and resets are in
the audit log, as before.

## Mail set-up

Codes go out through the app's normal mail settings (`MAIL_*` in `.env`).
With `MAIL_MAILER=log` they land in `storage/logs/laravel.log`, which is only
useful for testing. A real mail server must be set up before anyone relies on
e-mail codes.

## Database

Migration `2026_11_02_000100_add_email_codes_to_two_factor` adds four columns
to `users`:

- `two_factor_method` (`app` or `email`, default `app`)
- `two_factor_email_code` (hashed)
- `two_factor_email_expires_at`
- `two_factor_email_sent_at`

## Tests

`tests/Feature/Auth/EmailTwoFactorTest.php`:

- set-up by e-mail code: the resend wait, a wrong code, the confirm step,
  recovery codes and the audit entry
- a set-up code that has expired, and confirming without starting set-up
- sign-in: the code is sent once, works once, and cannot be reused
- resend: the one-minute wait, and only the newest code works
- an expired code is refused, and a new one is sent on reload
- a recovery code signs in, and turning off clears the e-mail code
- the company requirement accepts e-mail codes, and an owner's reset clears
  them

`TwoFactorTest` checks that the set-up page shows both choices.

## Manual checklist

1. Profile > Two-factor sign-in > **Use codes sent to my e-mail**. Check the
   e-mail (or `laravel.log`) and type the code. Save the recovery codes.
2. Sign out and sign in. A code arrives; type it.
3. Click **Send a new code** at once; it asks you to wait. After a minute it
   sends a new code, and the old one no longer works.
4. Wait 10 minutes; the old code is refused.
5. Sign in with a recovery code instead.
6. Turn two-factor off from the profile, then set it up again with an app.

## Not done

- SMS codes. They need an SMS provider account (and DLT registration in
  India), which the app does not have.
