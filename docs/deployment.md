# Deployment checklist (production)

Tested with PHP 8.3.6 and the dependency set in `composer.lock` (also resolvable on PHP 8.2:
`composer why-not php 8.2.0` reports nothing). MySQL 8.0: the full test suite passes and every
migration rolls back cleanly (`.github/workflows/tests.yml` checks PHP 8.2/8.3 × SQLite/MySQL).

## Server

- [ ] PHP 8.2 or 8.3 with extensions: pdo_mysql, mbstring, intl, gd, zip, xml, xmlreader,
      xmlwriter, fileinfo, openssl, ctype, iconv, zlib (needed by PhpSpreadsheet and dompdf).
- [ ] MySQL 8.0, database with `utf8mb4` / `utf8mb4_unicode_ci`; a user limited to that database.
- [ ] Web root is `public/`. `storage/` and `bootstrap/cache/` writable by the web user.
- [ ] HTTPS only (HSTS is sent automatically over HTTPS).
- [ ] `upload_max_filesize` / `post_max_size` ≥ 200M if backups will be uploaded (imports need 10M).

## `.env`

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, a fresh `APP_KEY`.
- [ ] `DB_*` for MySQL. `SESSION_SECURE_COOKIE=true`, `SESSION_DRIVER=database`.
- [ ] Mail settings (password reset and e-mail verification), `ERP_REQUIRE_EMAIL_VERIFICATION=true`.
- [ ] `ERP_BACKUP_PATH` if backups should go to a separate disk (default `storage/app/private/backups`).
- [ ] `LOG_LEVEL=warning` or as needed; logs in `storage/logs`.

## Install / upgrade

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force          # creates/updates tables, permissions and system roles
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Do **not** run `php artisan db:seed` in production: the demo company is only seeded when
`APP_ENV=local`.

## Scheduler and queue

- [ ] Cron: `* * * * * cd /path/to/tallyerp && php artisan schedule:run >> /dev/null 2>&1`
      (scheduled company backups at 01:30, clean-up of abandoned import files).
- [ ] `php artisan schedule:list` shows `erp:backup --scheduled` and `erp:clean-imports`.
- [ ] A queue worker (`php artisan queue:work`, e.g. under Supervisor) for mail.

## Backups

- [ ] Whole database: nightly `mysqldump --single-transaction` kept off the server.
- [ ] Company backups: turn on the schedule in Data → Backup & Restore and download copies.
- [ ] Test a restore on a staging copy before relying on it.

## After go-live

- [ ] Sign in as the owner, open Data → Integrity Check: all green.
- [ ] Check GST rates, professional-tax slabs and PF/ESI limits in `config/erp.php` against the
      current official rules before filing anything. GST portal, e-invoice and e-way bill
      connectivity are **not** integrated.
- [ ] Create users with the narrowest role they need (Users & Roles).
