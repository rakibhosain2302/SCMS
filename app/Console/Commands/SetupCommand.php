<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Nit\Master\Models\Branch;
use Nit\Master\Support\Branches;
use SCMS\Finance\Database\Seeders\ChartOfAccountsSeeder;
use SCMS\Finance\Database\Seeders\FinanceDemoSeeder;
use SCMS\Finance\Enums\CashBankKind;
use SCMS\Finance\Models\Account;
use SCMS\Finance\Models\CashBankAccount;
use SCMS\Finance\Services\CashBankService;
use SCMS\Finance\Services\FinancialYearService;

/**
 * First set-up of a samiti, safe to run again (what exists is kept): the first admin and the roles
 * (nit:install), the chart of accounts, the first branch, the financial year around today and the
 * branch's cash drawer. --samples also adds sample savings schemes and loan products.
 */
class SetupCommand extends Command
{
    protected $signature = 'scms:setup
        {--admin-email= : E-mail of the first admin (asked when missing)}
        {--admin-name=Administrator : Name of the first admin}
        {--branch= : Name of the first branch (asked when there is none)}
        {--branch-code= : Short code of the branch, used in member numbers (e.g. DHK)}
        {--year-start= : First day of the financial year (default: the 1 July before today)}
        {--cash-opening=0 : Cash in hand of the branch on the first day of the year}
        {--samples : Also add sample savings schemes and loan products}';

    protected $description = 'Set up SCMS: admin, roles, chart of accounts, first branch, financial year and cash drawer';

    public function handle(FinancialYearService $years, CashBankService $cash): int
    {
        $this->components->info('NIT Master: admin and roles');
        $email = $this->option('admin-email') ?: ($this->input->isInteractive() ? $this->ask('E-mail of the first admin') : null);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Give the e-mail of the first admin: --admin-email=you@example.com');

            return self::FAILURE;
        }
        $this->call('nit:install', array_filter(['--admin-email' => $email, '--admin-name' => $this->option('admin-name'), '--no-interaction' => true]));

        $this->components->info('Finance');
        $this->call('db:seed', ['--class' => $this->option('samples') ? FinanceDemoSeeder::class : ChartOfAccountsSeeder::class, '--force' => true]);

        $branch = Branch::query()->orderBy('id')->first();
        if (! $branch) {
            $name = $this->option('branch') ?: ($this->input->isInteractive() ? $this->ask('Name of the first branch', 'Head Office') : 'Head Office');
            $code = strtoupper((string) ($this->option('branch-code') ?: ($this->input->isInteractive() ? $this->ask('Branch code (used in member numbers)', 'HO') : 'HO')));
            $branch = Branch::query()->create(['name' => $name, 'code' => $code, 'active' => true]);
            Branches::flush();
            $this->components->twoColumnDetail("Branch {$branch->name} ({$branch->code})", '<fg=green>created</>');
        } else {
            $this->components->twoColumnDetail("Branch {$branch->name}", 'exists');
        }

        $start = $this->option('year-start')
            ? Carbon::parse($this->option('year-start'))
            : (today()->month >= 7 ? Carbon::create(today()->year, 7, 1) : Carbon::create(today()->year - 1, 7, 1));
        $year = $years->forDate(today());
        if (! $year) {
            $end = $start->copy()->addYear()->subDay();
            $year = $years->create(['name' => 'FY '.$start->format('Y').'–'.$end->format('y'), 'starts_on' => $start, 'ends_on' => $end]);
            $this->components->twoColumnDetail("Financial year {$year->name}", '<fg=green>created</>');
        } else {
            $this->components->twoColumnDetail("Financial year {$year->name}", 'exists');
        }

        $drawer = CashBankAccount::query()->withoutBranchScope()->where('branch_id', $branch->id)->where('kind', CashBankKind::Cash->value)->first();
        if (! $drawer) {
            $account = Account::query()->where('code', config('scms-finance.accounts.cash'))->firstOrFail();
            $drawer = $cash->create($branch->id, CashBankKind::Cash, $account->id, ['name' => "Cash in hand · {$branch->name}"], null,
                (string) $this->option('cash-opening'), Carbon::parse($year->starts_on));
            $this->components->twoColumnDetail("Cash drawer {$drawer->name}", '<fg=green>created</>');
        } else {
            $this->components->twoColumnDetail("Cash drawer {$drawer->name}", 'exists');
        }

        $this->newLine();
        $this->components->info('Done. Next: add staff on Authorization › Users, give each a branch and a role (Cashier, Branch Manager, …), then register members.');

        return self::SUCCESS;
    }
}
