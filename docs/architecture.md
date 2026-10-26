# Samiti Management System — Architecture

Status: inspection complete, no business modules built yet (2026-10-26).
Everything under "Existing architecture" and "Reusable nit-master functionality" was
checked against the code; everything after that is a proposal.

---

## 1. Existing architecture (verified)

### Platform

| Item | Value |
|---|---|
| PHP | 8.4.7 (`composer.json`: `^8.3`) |
| Laravel | 13.38.0 |
| Database | MariaDB 10.11, InnoDB, `scms_db` |
| Session / cache / queue | `database` driver for all three |
| App timezone | `UTC` (`config/app.php`), locale `en` |
| UI | nit-master layout (`nit::master`), Bootstrap 5 (`Paginator::useBootstrapFive()`, `data-bs-*`), Font Awesome, toastr + SweetAlert for flash messages |
| Front-end build | `package.json` has Vite + Tailwind 4 from the Laravel skeleton; nothing uses it. The panel uses nit-master's prebuilt assets in `public/nit/master` |
| PHP extensions missing | `bcmath`, `intl`, `pdo_sqlite` |

### Packages

Only one project-specific package: `nit/master` (`dev-master`, path repository
`/var/www/NIT/packages/nit-master`, namespace `Nit\Master`). The rest is the Laravel
skeleton (tinker, pint, pail, phpunit 12, mockery, faker).

### Application code (all of it)

| File | What it is |
|---|---|
| `app/Models/User.php` | Skeleton user + `Fileable`, `NitAuthorization` traits |
| `app/Http/Controllers/DashboardController.php` | Single action, `#[Permission('dashboard_view')]` |
| `resources/views/dashboard.blade.php` | Extends `nit::master` |
| `routes/web.php` | `/` → dashboard (`web, auth, authorization`), `/dashboard` → redirect |
| `app/Providers/AppServiceProvider.php` | Empty |
| `config/` | Laravel defaults only; no `sidebar.php`, `ipermissions.php`, `rolepermission.php` or `nit.php` in the app yet (package defaults apply) |

### Database (36 migrations ran: 3 skeleton + 33 nit-master)

Tables from nit-master that the business modules will use:

| Table | Use |
|---|---|
| `users` (+ `role_id`, `status`, `phone`, `deleted_at`, 2FA, OTP columns) | Staff accounts |
| `roles`, `role_user`, `role_permissions` (with `scope` all/own) | Roles, several roles per user, permissions |
| `branches` (`name`, `code` unique, `phone`, `address`, `active`), `branch_user` | Branches and branch-user assignment |
| `approvals` (`type`, `action`, `model`, `subject_id`, `payload` JSON, `status`, `requested_by`, `decided_by`, …) | Maker-checker |
| `activity_logs` | Who did what, with IP and browser |
| `settings`, `files`, `notifications` | Settings, uploads, bell notifications |

Current data: 1 user, 2 roles (`1 Admin` = super admin, `2 User` = registration role),
0 branches.

### Tests

- `phpunit.xml` uses SQLite in memory. **`pdo_sqlite` is not installed**, so any test that
  touches the database cannot run. The two current tests do not touch it and pass.
- nit-master's own suite (`packages/nit-master`, 186 tests) fails at setup for the same reason.

---

## 2. Reusable nit-master functionality (verified APIs)

| Need | nit-master provides | Notes |
|---|---|---|
| Sign-in, sign-up, reset, 2FA, OTP, passkeys | Package routes and controllers | Never replaced |
| Page permissions | `#[Permission('key', 'Title', ownable: bool)]` on controller actions + `authorization` middleware | Group name = controller name without `Controller` (`Member::member_view`) |
| Non-page abilities (approve, post, reverse) | App `config/ipermissions.php` → `@can` / `Gate::authorize` | |
| Roles in code | App `config/rolepermission.php` (`'Cashier' => ['Member::*', …]`), applied by `SyncConfigRoles` middleware or `php artisan nit:roles` | |
| Own records only | `HasOwner` trait (`created_by`) + `Model::visibleTo($user, $ability)` | |
| Branch isolation | `BelongsToBranch` trait: global scope `nit_branch` on `branch_id`, fills `branch_id` on create; `withoutBranchScope()` | See risk R3 |
| Current branch | `Branches::current()`, `visibleIds()`, `defaultFor()`, `forUser()`; header branch switcher | Session key `nit_branch` |
| Maker-checker | `Approvals::request()`, `::handler($type, $action, Closure)`, `::approve()` (runs inside `DB::transaction`), `::reject()`, `::cancel()`; statuses `pending, approved, rejected, cancelled, failed`; ability `<type>_approve`; nobody approves their own request | See risk R4 |
| Audit trail | `Activity::log($event, $description, $subject, $properties)`, `LogsActivity` trait | Pruned only if `nit:prune-activity` is scheduled |
| Lists | `Table::make($query, $request)->search()->sortable()->paginate()` / `->csv()` + `<x-nit::table>` | |
| Components | `x-nit::card, input, select, textarea, button, badge, table, empty-state, delete-button, avatar` | No modal component; Bootstrap 5 modals are used directly (`user/index.blade.php`) |
| Alerts | Flash `success` / `warning` / `message` → toastr, `error` → SweetAlert | |
| Print / PDF | `@extends('nit::print')`, `Pdf::download($view, $data, $file)` | Browser print handles Bangla; dompdf does not |
| Numbers and dates | `nit_number()`, `nit_money()`, `nit_date()` (Bangla digits when locale is `bn`) | |
| Notifications | `Notify::user()`, `Notify::superAdmins()` | |
| CRUD scaffolding | `php artisan nit:module Name --fields=… [--branch --approval --soft-delete --print --import --api]` | Good for master data only (see 4.3) |
| Menu | App `config/sidebar.php`, one array per module | |
| Dashboard widgets | `DashboardWidgets::register()` + `@can(DashboardWidgets::ability(...))` | |

---

## 3. Financial design

### 3.1 Authoritative source of balances

**Posted journal lines are the only source of truth.** Every balance (cash in hand, bank,
a member's savings, a loan's outstanding principal, income, expense) is the sum of
posted `journal_lines` for a GL account, optionally narrowed by a sub-ledger
(`savings_account_id`, `loan_account_id`, `member_id`).

`savings_accounts.balance` and `loan_accounts.principal_outstanding` exist only as
caches. They are updated in the same database transaction as the posting and can be
rebuilt from the ledger at any time (`php artisan samiti:rebuild-balances`, plus a
nightly reconciliation that reports any difference).

### 3.2 Money representation

`bcmath` is not installed and floats are not acceptable for money. Proposal: store every
amount as **`BIGINT` in paisa** (1 taka = 100), do arithmetic on integers, format with
`nit_money($paisa / 100)`. Alternative: install `bcmath`, use `DECIMAL(15,2)` and string
math. Decision D1.

`nit:module` generates `decimal(12,2)` columns; money columns in generated modules must
be changed by hand.

### 3.3 Transaction lifecycle

```
draft ──submit──▶ pending ──approve──▶ posted ──reverse──▶ reversed
  │                 │                    (immutable)
  └──delete         └──reject / cancel ──▶ rejected / cancelled
```

| State | Ledger effect | Who |
|---|---|---|
| `draft` | None; editable, deletable | Maker |
| `pending` | None; locked; an `approvals` row exists | Maker submits |
| `posted` | Journal entry written; balances updated | Checker approves, or maker when no approval is needed |
| `reversed` | A new reversing entry (all lines swapped) is posted with `reverses_id`; the original stays | Checker with `transaction_reverse` |
| `rejected` / `cancelled` | None | Checker / maker |

Rules:
- A posted entry is never edited or deleted. Corrections are reversals plus a new entry.
- Posting runs in one DB transaction with `SELECT … FOR UPDATE` on the affected
  account rows (no negative savings, no over-repayment, no double posting).
- `posting_date` (a business date, `DATE`) is separate from `created_at`
  (a UTC timestamp). Daily cashbook and reports use `posting_date`.
- Every posted entry gets a gap-free voucher number per branch and financial year
  (`DHK-2026-000123`), allocated inside the posting transaction.

Approvals are wired through nit-master with **custom handlers only**:

```php
// AppServiceProvider::boot()
Approvals::handler('transaction', 'post', fn (Approval $a) => app(PostingService::class)->post($a->subject_id));
Approvals::handler('transaction', 'reverse', fn (Approval $a) => app(ReversalService::class)->reverse($a->subject_id, $a->payload['reason']));
```

The built-in `create` / `update` / `delete` actions write the model directly with
`forceFill()->save()` and would bypass the posting rules, so they must not be used for
financial records. `Approvals::needed()` returns true for every non-super-admin and has
no per-type setting, so the app decides when approval is required (D4).

### 3.4 How journal entries are generated

Business screens never write journal lines. They create a `financial_transaction`
(type, amount components, accounts, member, product). On posting, `JournalBuilder`
turns it into balanced lines using the **GL mapping of the product**:

| Transaction type | Debit | Credit |
|---|---|---|
| `savings_deposit` | Cash / Bank | Member savings (liability) · sub-ledger savings account |
| `savings_withdrawal` | Member savings (liability) | Cash / Bank |
| `savings_interest` (if product pays it) | Interest expense | Member savings |
| `loan_disbursement` | Loan receivable (asset) · sub-ledger loan | Cash / Bank; processing fee → Fee income |
| `loan_repayment` | Cash / Bank | Loan receivable (principal) · Interest income · Penalty income · Fee income |
| `charge` (admission, passbook, …) | Cash / Bank or Member savings | Fee income |
| `income` | Cash / Bank | Income account |
| `expense` | Expense account | Cash / Bank |
| `transfer` (cash ↔ bank, bank ↔ bank) | Destination | Source |
| `opening_balance` | Per line | Per line |

`JournalBuilder` refuses to post unless total debit = total credit and every line has
an amount greater than 0. A DB check constraint (`debit >= 0 AND credit >= 0 AND
(debit = 0 OR credit = 0)`) backs it up.

### 3.5 Telling deposits, principal, charges, income and expenses apart

Three independent markers, so nothing depends on reading descriptions:

1. **GL account type** (`asset`, `liability`, `equity`, `income`, `expense`) and
   **sub-type** (`cash`, `bank`, `member_savings`, `loan_receivable`, `interest_income`,
   `fee_income`, `penalty_income`, …).
2. **Transaction type** on the header (`savings_deposit`, `loan_repayment`, …).
3. **Line component** on each journal line (`principal`, `interest`, `penalty`, `fee`,
   `deposit`, `withdrawal`). A repayment of 1,150 is stored as
   `principal 1,000 + interest 100 + penalty 50`, never as one amount.

Member deposits are liabilities (the samiti owes them); loan principal is an asset;
charges, interest and penalties are income; nothing a member deposits is ever income.

### 3.6 Configurable product rules (no interest assumed)

`loan_products`:
- `interest_method`: `none`, `flat`, `declining_balance` (D5)
- `interest_rate` (nullable; required unless `none`), `rate_period` (`yearly` / `monthly`)
- `repayment_frequency` (`weekly`, `monthly`, `one_time`), `installments`
- `processing_fee_type` (`none`, `fixed`, `percent`) and value
- `penalty_rule` (`none`, `fixed_per_installment`, `percent_of_overdue`) and value, grace days
- `allocation_order` (default `penalty → interest → principal`)
- min / max amount, guarantor count required
- GL mapping: `receivable_account_id`, `interest_income_account_id`,
  `fee_income_account_id`, `penalty_income_account_id`

`savings_products`:
- `kind`: `general`, `fixed` (DPS / FDR), `compulsory`
- `interest_method` (`none` by default), rate, posting frequency
- minimum balance, withdrawal allowed / notice days, monthly compulsory amount
- GL mapping: `liability_account_id`, `interest_expense_account_id`

A product's financial fields are frozen once an account uses it. Changes create a new
product version, so old loans keep their terms.

### 3.7 Branch isolation

1. Every business table has `branch_id NOT NULL` and uses `BelongsToBranch`.
2. Services check that every linked row belongs to the same branch as the transaction
   (member, savings account, loan, cash/bank account). The global scope alone is not
   enough, because it is skipped in console commands and for super admins.
3. Reports that cross branches call `withoutBranchScope()` explicitly and require a
   `report_all_branches` ability.
4. Non-admin staff must have at least one branch. nit-master treats a user **without** a
   branch as unlimited (sees every branch), so the user form or a middleware must
   enforce an assignment (risk R3).
5. Chart of accounts is shared; balances are always per branch (`journal_lines.branch_id`).
   Cash and bank accounts belong to one branch.

### 3.8 Financial years and opening balances

- `financial_years`: `name`, `starts_on`, `ends_on`, `status` (`open`, `closing`,
  `closed`), one open year at a time. Bangladesh cooperatives commonly use
  1 July – 30 June (D2).
- Posting is allowed only when `posting_date` falls inside an open year.
- Optional `period_locks` (`branch_id`, `locked_until`) close days or months after
  the cashbook is signed off.
- **Year-end close** (one service, one transaction, per branch):
  1. Checks that nothing is in `draft` / `pending` for the year.
  2. Posts a closing entry that moves every income and expense balance to
     *Accumulated surplus* (equity).
  3. Marks the year `closed`.
  4. Posts an `opening_balance` entry on the first day of the next year with the
     balance-sheet balances (assets, liabilities, equity).
- **Go-live opening balances**: entered once per branch as an `opening_balance`
  transaction (cash, bank, each member's savings, each loan's outstanding principal,
  equity as the balancing figure), approved like any other transaction. Opening
  balances for members and loans must be imported against their sub-ledgers, not as
  one total (D6).

---

## 4. Proposed module structure

### 4.1 Code layout

Standard Laravel folders with a domain sub-namespace, which matches how nit-master
groups abilities by controller name:

```
app/
  Models/                     Branch-scoped Eloquent models (one folder, flat)
  Http/Controllers/
    Members/                  MemberController, MemberDocumentController
    Savings/                  SavingsProductController, SavingsAccountController, DepositController, WithdrawalController
    Loans/                    LoanProductController, LoanApplicationController, DisbursementController, RepaymentController
    Accounting/               AccountController (chart), CashBankAccountController, TransactionController, JournalController, FinancialYearController
    Reports/                  CashbookController, LedgerController, TrialBalanceController, MonthlyReportController, AnnualReportController
  Services/
    Accounting/               PostingService, JournalBuilder, ReversalService, VoucherNumber, BalanceService, YearEndService
    Savings/                  SavingsService (deposit, withdraw, interest)
    Loans/                    LoanApplicationService, LoanScheduleService, DisbursementService, RepaymentAllocator
  Enums/                      TransactionType, TransactionStatus, AccountType, LineComponent, InterestMethod
  Support/Money.php           Paisa integer helpers (if D1 = BIGINT)
config/
  sidebar.php  ipermissions.php  rolepermission.php  samiti.php
resources/views/{members,savings,loans,accounting,reports}/
```

### 4.2 Module list and nit-master usage

| Module | Built with | Branch | Approval |
|---|---|---|---|
| Branches, branch-user | **nit-master as is** (Authorization › Branches, user form) | — | — |
| Financial years | Hand-written controller + `YearEndService` | No (global) | Close: yes |
| Chart of accounts | `nit:module` then edited (tree, type, sub-type) | No (global) | Optional |
| Cash and bank accounts | `nit:module --branch` then edited (GL link) | Yes | No |
| Members | `nit:module --branch --soft-delete --import --print` then edited | Yes | Optional (D4) |
| Savings / loan products | Hand-written (rules + GL mapping + versioning) | No | Yes |
| Savings accounts | Hand-written | Yes | Opening: optional |
| Deposits, withdrawals | Hand-written over `financial_transactions` | Yes | Per D4 |
| Loan applications | Hand-written (status flow, guarantors, schedule preview) | Yes | Yes |
| Disbursement, repayment | Hand-written over `financial_transactions` | Yes | Disbursement: yes |
| Income, expense, transfers | Hand-written over `financial_transactions` | Yes | Per D4 |
| Journal (manual) | Hand-written, accountants only | Yes | Always |
| Cashbook, reports | Read-only controllers + `nit::print` | Yes | — |
| Audit log | **nit-master** `activity_logs` + `Activity::log()` from every service | — | — |

### 4.3 When to use `nit:module`

Use it for master data with plain CRUD (members, chart of accounts, cash/bank accounts).
Do **not** use it for anything that moves money: its `--approval` uses the built-in
`create`/`update` actions (direct `forceFill()->save()`) and its money columns are
`decimal(12,2)`.

---

## 5. Database entity relationship plan

All business tables: `id`, `branch_id` (where branch-scoped), `created_by`
(`HasOwner`), timestamps. Money in paisa (`BIGINT UNSIGNED` unless noted).

```
branches 1─* branch_user *─1 users                      (nit-master)

financial_years 1─* journal_entries

accounts (chart)  self-referencing parent_id; type, sub_type, code unique, is_postable
  1─* cash_bank_accounts (branch_id, kind cash|bank, bank name, account no)
  1─* journal_lines

members (branch_id, member_no unique per branch, name, father/husband, NID, phone,
         address, admission_date, status active|inactive|closed, photo via files)
  1─* member_nominees
  1─* savings_accounts ─* savings_products
  1─* loan_applications ─* loan_products
  1─* loan_guarantors (as guarantor)

savings_products (rules, GL mapping, version, active)
savings_accounts (branch_id, member_id, product_id, account_no, opened_on, status,
                  balance [cache])

loan_products (rules, GL mapping, version, active)
loan_applications (branch_id, member_id, product_id, amount_requested, purpose,
                   status draft|submitted|approved|rejected|disbursed|cancelled)
  1─1 loan_accounts (branch_id, member_id, product_id snapshot of terms, principal,
                     disbursed_on, maturity_on, status active|closed|written_off,
                     principal_outstanding [cache])
        1─* loan_schedules (installment_no, due_on, principal_due, interest_due,
                            principal_paid, interest_paid, penalty_paid, status)

financial_transactions (branch_id, financial_year_id, type, status, posting_date,
                        member_id?, savings_account_id?, loan_account_id?,
                        cash_bank_account_id?, amount, components JSON, narration,
                        approval_id?, journal_entry_id?, reverses_id?, submitted_by,
                        posted_by, posted_at)
  1─1 journal_entries (branch_id, financial_year_id, voucher_no unique, posting_date,
                       source_type/source_id, reverses_id?, posted_by, posted_at)
        1─* journal_lines (branch_id, account_id, debit, credit, component,
                           member_id?, savings_account_id?, loan_account_id?)

voucher_sequences (branch_id, financial_year_id, last_no)   unique(branch, year)
period_locks (branch_id, locked_until)
```

Key indexes: `journal_lines (account_id, branch_id)`, `journal_lines (savings_account_id)`,
`journal_lines (loan_account_id)`, `journal_entries (branch_id, posting_date)`,
`financial_transactions (branch_id, status, posting_date)`.

Foreign keys use `restrictOnDelete()` for everything financial. Members, accounts and
products are soft-deleted or deactivated, never deleted once used.

---

## 6. Route and controller organization

```php
// routes/web.php
Route::middleware(['web', 'auth', 'authorization'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('members', MemberController::class);

    Route::prefix('savings')->as('savings.')->group(function () {
        Route::resource('products', SavingsProductController::class);
        Route::resource('accounts', SavingsAccountController::class);
        Route::resource('deposits', DepositController::class)->only('index', 'create', 'store', 'show');
        Route::resource('withdrawals', WithdrawalController::class)->only('index', 'create', 'store', 'show');
    });

    Route::prefix('loans')->as('loans.')->group(function () { /* products, applications, disbursements, repayments */ });

    Route::prefix('accounting')->as('accounting.')->group(function () {
        Route::resource('accounts', AccountController::class);
        Route::resource('cash-bank', CashBankAccountController::class);
        Route::resource('transactions', TransactionController::class)->only('index', 'show');
        Route::post('transactions/{transaction}/submit', [TransactionController::class, 'submit'])->name('transactions.submit');
        Route::post('transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])->name('transactions.reverse');
        Route::resource('journals', JournalController::class)->only('index', 'create', 'store', 'show');
        Route::resource('financial-years', FinancialYearController::class)->except('destroy');
    });

    Route::prefix('reports')->as('reports.')->group(function () { /* cashbook, ledger, trial-balance, monthly, annual */ });
});
```

Controllers stay thin: validate (Form Requests), call a service, redirect with a flash
message. Every action carries a `#[Permission]`.

---

## 7. Service layer design

| Service | Responsibility |
|---|---|
| `PostingService::post(FinancialTransaction)` | Lock rows, check year/period open and branch consistency, build lines, allocate voucher, write entry, update caches, set `posted`, `Activity::log('transaction.posted')` |
| `JournalBuilder::for(FinancialTransaction): array` | Pure function: transaction + product GL mapping → balanced lines; throws if unbalanced |
| `ReversalService::reverse(FinancialTransaction, string $reason)` | Post the mirror entry, mark original `reversed`, recalculate loan schedule / savings cache |
| `ApprovalGate::submit(FinancialTransaction)` | Decide if approval is needed (D4); either `Approvals::request('transaction', 'post', …)` or post at once |
| `SavingsService` | Deposit / withdrawal transactions, minimum balance and withdrawal rules, interest calculation |
| `LoanScheduleService` | Schedule for `none` / `flat` / `declining_balance`, fixed rounding rule (last installment absorbs rounding) |
| `RepaymentAllocator` | Split a payment into penalty / interest / principal by `allocation_order`, mark schedule rows |
| `DisbursementService` | Application `approved` → loan account + schedule + `loan_disbursement` transaction |
| `BalanceService` | Balances from the ledger by account / sub-ledger / branch / date; cache rebuild and reconciliation |
| `YearEndService` | Close and open financial years (3.8) |
| `VoucherNumber` | Gap-free numbers per branch and year using `voucher_sequences` with `lockForUpdate()` |

All write services run inside `DB::transaction()`. They take the acting user explicitly
(no hidden `auth()` calls) so they work from approval handlers, imports and console
commands, where the branch scope is not applied.

---

## 8. Permission integration strategy

- **Page abilities** via `#[Permission]`, one group per controller:
  `Member::member_view|member_create|member_edit|member_delete`,
  `Deposit::deposit_view|deposit_create`, `Repayment::…`, `Cashbook::cashbook_view`, …
- **Non-page abilities** in app `config/ipermissions.php`:

  ```php
  return [
      'Transaction' => [
          'icon' => 'fa-exchange-alt',
          'transaction_approve' => 'Approve and post transactions',   // = Approvals::ability('transaction')
          'transaction_reverse' => 'Reverse posted transactions',
          'transaction_backdate' => 'Post with a past date',
      ],
      'Loan' => ['loan_approve' => 'Approve loan applications', 'loan_write_off' => 'Write off loans'],
      'FinancialYear' => ['financial_year_close' => 'Close a financial year'],
      'Report' => ['report_all_branches' => 'Reports across all branches'],
  ];
  ```

- **Roles in code**, `config/rolepermission.php`, so every install starts the same:

  | Role | Gets |
  |---|---|
  | Field officer | Members (view/create), deposits, repayments (create), own records only |
  | Cashier | Deposits, withdrawals, repayments, income/expense entry, cashbook view |
  | Branch manager | All branch pages, `transaction_approve`, `loan_approve`, branch reports |
  | Accountant | Chart of accounts, journals, `transaction_reverse`, all reports |
  | Auditor | View-only everything + `report_all_branches` |
  | Admin (role 1) | Everything (super admin, nit-master) |

- Maker-checker is guaranteed by nit-master (nobody approves their own request).
- Field officers get `ownable` abilities (`HasOwner::visibleTo`) on members and
  collections where the samiti wants it (D7).

---

## 9. Implementation roadmap

| Phase | Scope | Exit criteria |
|---|---|---|
| **0. Foundation** | Decide D1–D3; install `pdo_sqlite` (or a MySQL test DB) and `bcmath` if chosen; set `APP_TIMEZONE=Asia/Dhaka` for business dates; create `config/sidebar.php`, `ipermissions.php`, `rolepermission.php`, `samiti.php`; create branches; schedule `nit:prune-activity` only with a long retention | Tests can use the database; roles exist |
| **1. Accounting core** | Financial years, chart of accounts (seeded default chart), cash/bank accounts, `financial_transactions`, journal tables, `JournalBuilder`, `PostingService`, `ReversalService`, voucher numbers, manual journal, opening balances | Trial balance always balances; reversal tested; branch checks tested |
| **2. Members** | Members, nominees, documents, import | Member search, print member card |
| **3. Savings** | Products, accounts, deposits, withdrawals, statement | Savings balance = ledger balance |
| **4. Loans** | Products, applications, approval, schedule, disbursement, repayment, overdue | Schedule and allocation unit-tested for each interest method |
| **5. Income, expense, transfers** | Entry screens on the same engine | |
| **6. Reports** | Daily cashbook, ledger, trial balance, monthly and annual (receipts & payments, income & expenditure, balance sheet), member statements, loan overdue | Cashbook closing = next day's opening |
| **7. Year end** | Close/open year, period locks | Closing entry and new opening balances tested |

Each phase ships with feature tests for permissions, branch isolation and the posting
rules of that phase.

---

## 10. Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | `pdo_sqlite` missing: no database tests can run (app and nit-master) | Install `php8.4-sqlite3`, or point `phpunit.xml` at a separate MariaDB test database. Never at `scms_db` |
| R2 | No `bcmath`: float math on money | Integer paisa (D1) or install `bcmath` |
| R3 | nit-master treats a user without any branch as **unlimited**, and the branch scope is off in console and for super admins | Require a branch for non-admin staff; explicit branch checks in services; console jobs pass the branch explicitly |
| R4 | nit-master approvals: built-in create/update/delete bypass services; `needed()` has no per-type setting | Custom handlers only; app-level approval policy (D4) |
| R5 | `APP_TIMEZONE=UTC`: a deposit at 01:00 Dhaka time lands on the previous UTC day | Separate `posting_date` (business date) + Dhaka timezone for the app |
| R6 | Activity log can be pruned (`NIT_ACTIVITY_KEEP_DAYS=180`) | Financial audit lives in immutable journal/transaction tables; activity log is supplementary |
| R7 | nit-master is `dev-master` from a local path; package changes affect this app directly | Tag versions of nit-master; pin a version before production |
| R8 | Concurrent postings (two cashiers withdrawing from the same account) | Row locks in `PostingService`; unique voucher numbers via `voucher_sequences` |
| R9 | Rounding in interest schedules | One documented rounding rule; last installment absorbs the difference |

## 11. Business decisions needed

| # | Decision | Recommendation |
|---|---|---|
| D1 | Money storage: integer paisa or `DECIMAL(15,2)` + `bcmath` | Integer paisa |
| D2 | Financial year dates | 1 July – 30 June |
| D3 | Is the chart of accounts shared by all branches, and is there a head office branch? | Shared chart; head office as a branch |
| D4 | Which transactions need a second person, and above what amount? | Withdrawals, disbursements, reversals, manual journals always; deposits never |
| D5 | Interest methods in use (flat, declining, none) and how the samiti rounds | All three supported; confirm rounding |
| D6 | Go-live data: import existing members, savings balances and running loans? From what format? | CSV import per sub-ledger |
| D7 | Should field officers see only the members they registered? | Yes, via `ownable` |
| D8 | Inter-branch transactions (member of branch A pays at branch B)? | Not in the first release |
| D9 | Savings interest / dividend: computed by the system or entered manually at year end? | Manual entry first |
| D10 | Can a member hold several savings accounts of one product? | One per product |

---

## 12. Recommended next step

Answer **D1, D2 and D4**, fix the test database (R1), then build **Phase 1, the accounting
core** (financial years, chart of accounts, cash/bank accounts and the posting engine).
Every money-moving module depends on it, and it can be tested on its own with manual
journals before any member screens exist.
