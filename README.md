# TallyERP — Business ERP & Accounting

A responsive, web-based accounting and business management application inspired by the
functional areas of TallyPrime (accounting, sales, purchases, inventory, GST, banking,
payroll, reporting, audit). It is an original application with its own interface,
code and database schema.

## Stack

| Component | Version |
|-----------|---------|
| PHP | 8.2 or 8.3 (tested on **PHP 8.3.6**) |
| Laravel | 12.x |
| Database | MySQL 8 (production) · SQLite in-memory (automated tests) |
| UI | Blade, Bootstrap 5.3 (CDN), Bootstrap Icons, vanilla JavaScript |
| Tests | PHPUnit 11 |

No front-end build step is needed: styles live in `public/css/erp.css` and scripts in `public/js/erp.js`.

## Installation

```bash
git clone https://github.com/aasthasrkwebapp-code/tallyerp.git
cd tallyerp
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` for MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tallyerp
DB_USERNAME=root
DB_PASSWORD=secret

# Set to false on a local machine without a mail server
ERP_REQUIRE_EMAIL_VERIFICATION=false
```

Then:

```bash
php artisan migrate --seed      # creates tables, permissions, system roles (and demo data when APP_ENV=local)
php artisan storage:link        # serves uploaded company logos
php artisan serve
```

Demo login (local only): **demo@example.com / password**.

## Running tests

```bash
php artisan test
```

Tests use an in-memory SQLite database (see `phpunit.xml`) and never touch your MySQL data.

## Module status

See [`docs/STATUS.md`](docs/STATUS.md) for the completed / partial / missing list per module,
and the `docs/` folder for each module's manual testing checklist.

## Architecture notes

* **Multi-company (tenant isolation).** Users belong to companies through `company_user`
  with a role per company. The `company` middleware resolves the active company and every
  company-owned model uses the `BelongsToCompany` trait, which adds a global scope, so
  records from another company return 404.
* **Permissions.** Keys like `vouchers.post` are defined in `config/erp.php`, seeded into
  `permissions`, and granted to roles. Routes use `can:<key>` middleware. Owner and admin
  hold every permission. Companies can define custom roles.
* **Audit trail.** Models using the `Auditable` trait write `audit_logs` rows with old and
  new values, user, IP and browser. Logins, logouts and failed logins are logged too.
* **Money.** Amounts are stored as `DECIMAL(18,2)` and calculated in integer paise
  (`App\Support\Money`); floating point is never used for totals.
