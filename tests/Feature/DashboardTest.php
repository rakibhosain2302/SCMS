<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nit\Master\Models\Branch;
use Nit\Master\Models\Role;
use Nit\Master\Support\Branches;
use Nit\Master\Support\RoleDefaults;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $abilities, array $branchIds = [], ?int $roleId = null): User
    {
        if ($roleId === null) {
            $role = Role::query()->create(['uuid' => (string) Str::uuid(), 'title' => 'R '.Str::random(4), 'alias' => 'r_'.Str::random(6)]);
            RoleDefaults::grant($role->id, $abilities);
            $roleId = $role->id;
        }
        $user = new User();
        $user->forceFill(['uuid' => (string) Str::uuid(), 'name' => 'U', 'email' => Str::random(8).'@example.com', 'password' => bcrypt('a-long-password'),
            'role_id' => $roleId, 'status' => 'active', 'email_verified_at' => now()])->save();
        foreach ($branchIds as $id) {
            DB::table('branch_user')->insert(['branch_id' => $id, 'user_id' => $user->id]);
        }
        Branches::flush();

        return $user;
    }

    public function test_a_super_admin_sees_members_money_and_branches(): void
    {
        $admin = new Role(['uuid' => (string) Str::uuid(), 'title' => 'Admin', 'alias' => 'admin']);
        $admin->id = 1;
        $admin->save();
        Branch::query()->create(['name' => 'Alpha', 'code' => 'ALP', 'active' => true]);
        Branch::query()->create(['name' => 'Beta', 'code' => 'BET', 'active' => true]);
        Branches::flush();

        $this->actingAs($this->user([], [], 1))->get('/')->assertOk()
            ->assertSee(__('Total members'))
            ->assertSee(__('Savings collected'))
            ->assertSee(__('Loans outstanding'));
    }

    public function test_each_part_needs_its_permission(): void
    {
        $branch = Branch::query()->create(['name' => 'Alpha', 'code' => 'ALP', 'active' => true]);
        Branches::flush();

        $plain = $this->user(['Dashboard::dashboard_view'], [$branch->id]);
        $this->actingAs($plain)->get('/')->assertOk()->assertSee(__('You are signed in.'))->assertDontSee(__('Total members'))->assertDontSee(__('Cash in hand'));

        $clerk = $this->user(['Dashboard::dashboard_view', 'Member::member_view'], [$branch->id]);
        $this->actingAs($clerk)->get('/')->assertOk()->assertSee(__('Total members'))->assertDontSee(__('Cash in hand'));
    }

    public function test_staff_without_a_branch_see_no_member_figures(): void
    {
        Branch::query()->create(['name' => 'Alpha', 'code' => 'ALP', 'active' => true]);
        Branches::flush();

        $this->actingAs($this->user(['Dashboard::dashboard_view', 'Member::member_view']))->get('/')->assertOk()->assertDontSee(__('Total members'));
    }
}
