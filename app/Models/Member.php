<?php

namespace App\Models;

use App\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\Master\Models\Concerns\BelongsToBranch;
use Nit\Master\Models\Concerns\HasOwner;
use Nit\Master\Models\Concerns\LogsActivity;
use SCMS\Finance\Models\Loan;
use SCMS\Finance\Models\SavingsAccount;

/**
 * A samiti member. Savings accounts and loans live in scms-finance and point here by member_id
 * (scms-finance.models.member). member_no, serial, branch_id and status are set by MemberService.
 */
class Member extends Model
{
    use BelongsToBranch, HasOwner, LogsActivity;

    protected $fillable = [
        'name', 'name_bn', 'father_name', 'mother_name', 'spouse_name', 'gender', 'date_of_birth', 'nid',
        'phone', 'email', 'occupation', 'present_address', 'permanent_address', 'admission_date', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
            'closed_on' => 'date',
            'status' => MemberStatus::class,
        ];
    }

    public function activityLabel(): string
    {
        return "{$this->member_no} {$this->name}";
    }

    public function nominees(): HasMany
    {
        return $this->hasMany(MemberNominee::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class)->latest();
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class)->withoutBranchScope();
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class)->withoutBranchScope();
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }
}
