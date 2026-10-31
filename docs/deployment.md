# Deployment and first set-up

## Server

- PHP 8.4 with `pdo_mysql`, MariaDB 10.11 / MySQL 8, a web server pointing at `public/`.
- Cron (scheduler): `* * * * * cd /path/to/scms && php artisan schedule:run >> /dev/null 2>&1`
  It runs the daily backup (02:00), the SMS reminders of installments (09:00), the weekly check of
  cached balances, and nit-master's clean-ups.

## Install

```sh
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# set DB_*, APP_URL, MAIL_* in .env, then:
php artisan migrate --force
php artisan scms:setup --admin-email=you@example.com --branch="Head Office" --branch-code=HO --cash-opening=0
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`scms:setup` is safe to run again. It prints the admin's password once. Add `--samples` for sample
savings schemes and loan products.

## .env for production

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_TIMEZONE` | `Asia/Dhaka` (business dates) |
| `SCMS_FINANCE_MEMBER_MODEL` / `SCMS_FINANCE_MEMBER_TABLE` | `App\Models\Member` / `members` |
| `SCMS_FINANCE_SELF_POSTING` | `false`: the writer of a draft cannot post it |
| `SCMS_FINANCE_REQUIRE_BRANCH` | `true`: staff without a branch see nothing |
| `SCMS_FINANCE_WITHDRAWAL_APPROVAL_ABOVE` | e.g. `50000`; empty = no approval |
| `SCMS_FINANCE_SHARE_PRICE` | price of one share, or empty |
| `SCMS_FINANCE_SMS` | `true` to text members; the gateway is set on Settings › Mail & SMS |
| `SCMS_ADMISSION_FEE` | fee suggested at registration, or empty |
| `NIT_REGISTRATION` | `false`: only admins create staff accounts |

## Go-live with existing data

1. Branches, staff (Authorization › Users, each with a branch and a role).
2. Members: Members › Import members (CSV, check first).
3. Cash and bank accounts with their opening balance (Finance › Cash & bank).
4. Savings accounts with their opening balance (ability "Enter opening balances").
5. Running loans: Finance › Loans › Bring in running loans (form or CSV, check first).
6. Share capital already paid: sell shares dated on the go-live day.
7. Check the trial balance: it must balance, and opening balance equity shows the net position brought in.

## Tests

```sh
php artisan test                                   # app, on the scms_test database
cd ../packages/scms-finance && FINANCE_TEST_DB_DATABASE=scms_finance_test \
  FINANCE_TEST_DB_USERNAME=... FINANCE_TEST_DB_PASSWORD=... vendor/bin/phpunit
```

Both need their own empty MariaDB database; the app's tests refuse any database but `scms_test`.
