<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nit\Master\Models\Branch;
use Nit\Master\Models\Role;
use Nit\Master\Support\Branches;
use Nit\Master\Support\RoleDefaults;
use SCMS\Finance\Database\Seeders\ChartOfAccountsSeeder;
use SCMS\Finance\Enums\CashBankKind;
use SCMS\Finance\Enums\YearStatus;
use SCMS\Finance\Models\Account;
use SCMS\Finance\Models\FinancialYear;
use SCMS\Finance\Models\SavingsScheme;
use SCMS\Finance\Services\CashBankService;
use SCMS\Finance\Services\SavingsService;
use SCMS\Finance\Services\SavingsTransactionService;
use SCMS\Finance\Support\AccountMap;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    private Branch $alpha;

    private Branch $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Branches::flush();
        $this->alpha = Branch::query()->create(['name' => 'Alpha', 'code' => 'ALP', 'active' => true]);
        $this->beta = Branch::query()->create(['name' => 'Beta', 'code' => 'BET', 'active' => true]);
        Branches::flush();
    }

    public function test_a_member_is_registered_with_the_next_number_of_the_branch_and_nominees(): void
    {
        $this->actingAs($this->admin())->post('/members', $this->form(['nominees' => [
            ['name' => 'Karim', 'relation' => 'Son', 'share' => 60],
            ['name' => 'Salma', 'relation' => 'Daughter', 'share' => 40],
        ]]))->assertRedirect();

        $this->actingAs($this->admin())->post('/members', $this->form(['name' => 'Second', 'nid' => null, 'phone' => '01812345678']))->assertRedirect();

        $members = Member::query()->orderBy('id')->get();
        $this->assertSame(['ALP-000001', 'ALP-000002'], $members->pluck('member_no')->all());
        $this->assertSame(2, $members[0]->nominees()->count());
        $this->assertSame('active', $members[0]->status->value);
    }

    public function test_an_existing_member_number_can_be_kept_and_numbers_and_nids_stay_unique(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/members', $this->form(['member_no' => 'OLD-77']))->assertRedirect();
        $this->assertTrue(Member::query()->where('member_no', 'OLD-77')->exists());

        $this->actingAs($admin)->post('/members', $this->form(['member_no' => 'OLD-77', 'phone' => '01912345678']))
            ->assertSessionHasErrors(['member_no', 'nid']);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->actingAs($this->admin())->post('/members', $this->form([
            'phone' => '12345', 'nid' => '123', 'admission_date' => today()->addDay()->toDateString(),
            'nominees' => [['name' => 'A', 'relation' => 'Son', 'share' => 50]],
        ]))->assertSessionHasErrors(['phone', 'nid', 'admission_date', 'nominees']);

        $this->assertSame(0, Member::query()->count());
    }

    public function test_branch_staff_only_see_and_register_members_of_their_branch(): void
    {
        $theirs = $this->member($this->alpha);
        $other = $this->member($this->beta, ['nid' => null, 'phone' => '01812345678']);
        $clerk = $this->user(['Member::member_view', 'Member::member_create'], [$this->alpha->id]);
        $this->flushSession(); // the admin's "Member … registered" message would name the other member

        $this->actingAs($clerk)->get('/members')->assertOk()->assertSee($theirs->member_no)->assertDontSee($other->member_no);
        $this->actingAs($clerk)->get("/members/{$other->id}")->assertNotFound();
        $this->actingAs($clerk)->post('/members', $this->form(['branch_id' => $this->beta->id, 'nid' => null, 'phone' => '01912345678']))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_staff_without_a_branch_are_refused_while_super_admins_are_not(): void
    {
        $nobody = $this->user(['Member::member_view'], []);

        $this->actingAs($nobody)->get('/members')->assertForbidden();
        $this->actingAs($this->admin())->get('/members')->assertOk();
    }

    public function test_the_business_day_is_dhaka_time(): void
    {
        $this->assertSame('Asia/Dhaka', config('app.timezone'));
    }

    public function test_without_the_ability_the_pages_are_refused(): void
    {
        $member = $this->member($this->alpha);
        $viewer = $this->user(['Member::member_view'], [$this->alpha->id]);

        $this->actingAs($viewer)->get("/members/{$member->id}")->assertOk();
        $this->actingAs($viewer)->get('/members/create')->assertStatus(401);
        $this->actingAs($viewer)->put("/members/{$member->id}", $this->form())->assertStatus(401);
        $this->actingAs($viewer)->delete("/members/{$member->id}")->assertStatus(401);
    }

    public function test_documents_are_private_and_only_served_for_their_member(): void
    {
        Storage::fake(MemberDocument::DISK);
        $admin = $this->admin();
        $member = $this->member($this->alpha);
        $other = $this->member($this->alpha, ['nid' => null, 'phone' => '01812345678']);

        $this->actingAs($admin)->post("/members/{$member->id}/documents", ['type' => 'nid', 'file' => UploadedFile::fake()->create('nid.pdf', 100, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $document = MemberDocument::query()->firstOrFail();
        Storage::disk(MemberDocument::DISK)->assertExists($document->path);

        $this->actingAs($admin)->get("/members/{$member->id}/documents/{$document->id}")->assertOk();
        $this->actingAs($admin)->get("/members/{$other->id}/documents/{$document->id}")->assertNotFound();
        $this->actingAs($admin)->post("/members/{$member->id}/documents", ['type' => 'photo', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->actingAs($admin)->delete("/members/{$member->id}/documents/{$document->id}")->assertRedirect();
        Storage::disk(MemberDocument::DISK)->assertMissing($document->path);
    }

    public function test_finance_uses_the_member_and_the_profile_shows_the_ledger(): void
    {
        $admin = $this->admin();
        $member = $this->member($this->alpha);
        [$scheme, $cash] = $this->finance();

        $account = app(SavingsService::class)->open([
            'branch_id' => $this->alpha->id, 'member_id' => $member->id, 'savings_scheme_id' => $scheme->id, 'opened_on' => today()->toDateString(),
        ], $admin);
        app(SavingsTransactionService::class)->deposit($account, ['amount' => '1500', 'transaction_date' => today()->toDateString(), 'cash_bank_account_id' => $cash->id], $admin);

        $this->actingAs($admin)->get("/members/{$member->id}")->assertOk()
            ->assertSee($account->account_number)
            ->assertSee('1,500.00');

        // A member with an open account can be neither closed nor deleted.
        $this->actingAs($admin)->post("/members/{$member->id}/status", ['status' => 'closed'])->assertSessionHasErrors('status');
        $this->actingAs($admin)->delete("/members/{$member->id}")->assertSessionHasErrors('member');
        $this->assertSame('active', $member->fresh()->status->value);

        // The member's branch is enforced by finance: an account in another branch is refused.
        $this->expectException(\SCMS\Finance\Exceptions\FinanceException::class);
        app(SavingsService::class)->open(['branch_id' => $this->beta->id, 'member_id' => $member->id, 'savings_scheme_id' => $scheme->id, 'opened_on' => today()->toDateString()], $admin);
    }

    public function test_a_member_without_finance_records_can_be_closed_reopened_and_deleted(): void
    {
        $admin = $this->admin();
        $member = $this->member($this->alpha);

        $this->actingAs($admin)->post("/members/{$member->id}/status", ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $member->fresh()->status->value);
        $this->assertNotNull($member->fresh()->closed_on);

        $this->actingAs($admin)->post("/members/{$member->id}/status", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertNull($member->fresh()->closed_on);

        $this->actingAs($admin)->delete("/members/{$member->id}")->assertRedirect('/members');
        $this->assertSame(0, Member::query()->count());
    }

    public function test_an_admission_fee_taken_at_registration_is_booked_as_income(): void
    {
        [, $cash] = $this->finance();
        $this->actingAs($this->admin())->post('/members', $this->form(['admission_fee' => '200', 'fee_cash_bank_account_id' => $cash->id]))->assertSessionHasNoErrors();

        $member = Member::query()->firstOrFail();
        $this->assertSame('200.00', $member->admission_fee);
        $this->assertNotNull($member->admission_fee_entry_id);
        $this->assertSame('200.00', app(\SCMS\Finance\Services\LedgerService::class)->accountBalance(Account::query()->where('code', '4500')->firstOrFail())->toString());
        $this->assertSame('200.00', app(\SCMS\Finance\Services\LedgerService::class)->cashBankBalance($cash->id)->toString());
    }

    public function test_staff_without_the_fee_ability_cannot_take_one(): void
    {
        [, $cash] = $this->finance();
        $clerk = $this->user(['Member::member_view', 'Member::member_create'], [$this->alpha->id]);

        $this->actingAs($clerk)->post('/members', $this->form(['admission_fee' => '200', 'fee_cash_bank_account_id' => $cash->id]))->assertSessionHasErrors('admission_fee');
        $this->assertSame(0, Member::query()->withoutBranchScope()->count());
    }

    public function test_members_are_imported_from_csv_after_a_check(): void
    {
        $csv = "branch_code,member_no,name,phone,present_address,admission_date,nid\n"
            ."ALP,,Rahima,01712345678,Mirpur,".today()->toDateString().",1234567890\n"
            ."BET,,Karim,01812345678,Uttara,".today()->toDateString().",\n"
            ."ALP,,Bad phone,123,Mirpur,".today()->toDateString().",\n";
        $file = fn () => UploadedFile::fake()->createWithContent('members.csv', $csv);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/members/import', ['file' => $file(), 'dry_run' => '1'])->assertRedirect('/members/import');
        $this->assertSame(0, Member::query()->count());
        $this->assertSame(2, session('import_report')['valid']);

        $this->actingAs($admin)->post('/members/import', ['file' => $file()])->assertRedirect();
        $this->assertSame(['ALP-000001', 'BET-000001'], Member::query()->orderBy('id')->pluck('member_no')->all());
        $this->assertSame(1, count(session('import_report')['skipped']));
    }

    public function test_the_statement_shows_the_savings_ledger(): void
    {
        $admin = $this->admin();
        $member = $this->member($this->alpha);
        [$scheme, $cash] = $this->finance();
        $account = app(SavingsService::class)->open(['branch_id' => $this->alpha->id, 'member_id' => $member->id, 'savings_scheme_id' => $scheme->id, 'opened_on' => today()->toDateString()], $admin);
        app(SavingsTransactionService::class)->deposit($account, ['amount' => '750', 'transaction_date' => today()->toDateString(), 'cash_bank_account_id' => $cash->id], $admin);

        $this->actingAs($admin)->get("/members/{$member->id}/statement")->assertOk()->assertSee($account->account_number)->assertSee('750.00');
    }

    public function test_the_pages_render(): void
    {
        $admin = $this->admin();
        $member = $this->member($this->alpha);

        foreach (['/members', '/members/create', '/members/import', "/members/{$member->id}", "/members/{$member->id}/edit", "/members/{$member->id}/statement", '/'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'branch_id' => $this->alpha->id,
            'name' => 'Rahima Begum',
            'phone' => '01712345678',
            'nid' => '1234567890',
            'present_address' => 'Mirpur, Dhaka',
            'admission_date' => today()->toDateString(),
        ];
    }

    private function member(Branch $branch, array $overrides = []): Member
    {
        $this->actingAs($this->admin())->post('/members', $this->form(['branch_id' => $branch->id] + $overrides))->assertSessionHasNoErrors();

        return Member::query()->withoutBranchScope()->latest('id')->firstOrFail();
    }

    private ?User $admin = null;

    private function admin(): User
    {
        if (! Role::query()->find(1)) {
            $role = new Role(['uuid' => (string) Str::uuid(), 'title' => 'Admin', 'alias' => 'admin']);
            $role->id = 1;
            $role->save();
        }

        return $this->admin ??= $this->makeUser(1);
    }

    /** @param  list<string>  $abilities  "Group::ability" as on the role form */
    private function user(array $abilities, array $branchIds): User
    {
        $role = Role::query()->create(['uuid' => (string) Str::uuid(), 'title' => 'Clerk '.Str::random(4), 'alias' => 'clerk_'.Str::random(6)]);
        RoleDefaults::grant($role->id, $abilities);
        $user = $this->makeUser($role->id);
        foreach ($branchIds as $id) {
            DB::table('branch_user')->insert(['branch_id' => $id, 'user_id' => $user->id]);
        }
        Branches::flush();

        return $user;
    }

    private function makeUser(int $roleId): User
    {
        $user = new User();
        $user->forceFill([
            'uuid' => (string) Str::uuid(), 'name' => 'User '.Str::random(4), 'email' => Str::random(8).'@example.com',
            'password' => bcrypt('a-long-password'), 'role_id' => $roleId, 'status' => 'active', 'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /** @return array{0: SavingsScheme, 1: \SCMS\Finance\Models\CashBankAccount} */
    private function finance(): array
    {
        app(AccountMap::class)->flush();
        (new ChartOfAccountsSeeder())->run();
        $year = new FinancialYear(['name' => 'FY', 'starts_on' => today()->subMonths(6), 'ends_on' => today()->addMonths(6)]);
        $year->forceFill(['status' => YearStatus::Open->value])->save();

        $scheme = SavingsScheme::query()->create([
            'code' => 'GEN', 'name' => 'General Savings', 'frequency' => 'flexible', 'min_deposit' => '10', 'minimum_balance' => '0',
            'allow_withdrawal' => true, 'liability_account_id' => Account::query()->where('code', '2100')->value('id'), 'is_active' => true,
        ]);
        $cash = app(CashBankService::class)->create($this->alpha->id, CashBankKind::Cash, Account::query()->where('code', '1101')->value('id'), ['name' => 'Alpha cash'], null);

        return [$scheme, $cash];
    }
}
