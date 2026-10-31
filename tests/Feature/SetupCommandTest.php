<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nit\Master\Models\Branch;
use SCMS\Finance\Models\CashBankAccount;
use SCMS\Finance\Models\FinancialYear;
use SCMS\Finance\Models\SavingsScheme;
use SCMS\Finance\Services\LedgerService;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_creates_the_admin_branch_year_and_cash_drawer_once(): void
    {
        $run = fn () => $this->artisan('scms:setup', ['--admin-email' => 'admin@samiti.test', '--branch' => 'Head Office', '--branch-code' => 'ho',
            '--cash-opening' => '25000', '--samples' => true, '--no-interaction' => true])->assertSuccessful();

        $run();
        $run(); // safe to run again

        $this->assertSame(1, (int) User::query()->where('email', 'admin@samiti.test')->value('role_id'));
        $this->assertSame(['HO'], Branch::query()->pluck('code')->all());
        $this->assertSame(1, FinancialYear::query()->count());
        $this->assertTrue(SavingsScheme::query()->exists());
        $drawer = CashBankAccount::query()->withoutBranchScope()->sole();
        $this->assertSame('25000.00', app(LedgerService::class)->cashBankBalance($drawer->id)->toString());
    }

    public function test_setup_needs_an_admin_email(): void
    {
        $this->artisan('scms:setup', ['--no-interaction' => true])->assertFailed();
    }
}
