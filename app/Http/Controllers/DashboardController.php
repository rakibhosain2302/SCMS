<?php

namespace App\Http\Controllers;

use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Http\Request;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Branches;
use SCMS\Finance\Services\DashboardService;
use SCMS\Finance\Services\ReportService;
use SCMS\Finance\Support\Abilities;

/** The landing page: members and money at a glance, each part only for those allowed to see it. */
class DashboardController extends Controller
{
    #[Permission('dashboard_view', 'View the dashboard')]
    public function __invoke(Request $request, DashboardService $finance, ReportService $reports)
    {
        $user = $request->user();
        // Staff without a branch would see every branch's members (nit-master's rule): show them nothing.
        $hasBranch = $user->isSuperAdmin() || ! Branches::enabled() || Branches::assignedIds($user) !== [];

        $members = null;
        if ($hasBranch && $user->can('member_view')) {
            $members = [
                'total' => Member::query()->count(),
                'active' => Member::query()->where('status', MemberStatus::Active->value)->count(),
                'new' => Member::query()->whereDate('admission_date', '>=', today()->startOfMonth())->count(),
            ];
        }

        $summary = $user->can(Abilities::DASHBOARD) ? $finance->summary($user) : null;

        $branches = null;
        if ($user->can(Abilities::REPORT_VIEW) && Branches::forUser($user)->count() > 1) {
            $balances = $reports->balancesByBranch($reports->branchScope($user));
            $memberCounts = $members !== null ? Member::query()->where('status', MemberStatus::Active->value)->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) as n')->pluck('n', 'branch_id') : collect();
            $branches = Branches::forUser($user)->filter(fn ($b) => isset($balances[$b->id]) || $memberCounts->has($b->id))
                ->map(fn ($b) => ['name' => $b->name, 'members' => (int) ($memberCounts[$b->id] ?? 0), 'balances' => $balances[$b->id] ?? null]);
        }

        return view('dashboard', compact('members', 'summary', 'branches'));
    }
}
