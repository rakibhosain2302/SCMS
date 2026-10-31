<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberDocument;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nit\Master\Models\Branch;
use Nit\Master\Support\Activity;
use SCMS\Finance\Enums\LineComponent;
use SCMS\Finance\Enums\LoanStatus;
use SCMS\Finance\Enums\SavingsAccountStatus;
use SCMS\Finance\Exceptions\FinanceException;
use SCMS\Finance\Models\Account;
use SCMS\Finance\Models\CashBankAccount;
use SCMS\Finance\Services\JournalService;
use SCMS\Finance\Support\Money;

/**
 * Registration, changes, status and documents of members. Every write runs in one transaction;
 * member numbers are given out with the branch row locked, so two clerks never get the same one.
 */
class MemberService
{
    /** @param  array<string, mixed>  $data  validated MemberRequest input */
    public function create(array $data, Authenticatable $user): Member
    {
        return DB::transaction(function () use ($data, $user) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($data['branch_id']);
            $serial = (int) Member::withoutBranchScope()->where('branch_id', $branch->id)->max('serial') + 1;

            $member = new Member($data);
            $member->forceFill([
                'branch_id' => $branch->id,
                'serial' => $serial,
                'member_no' => filled($data['member_no'] ?? null) ? trim($data['member_no']) : $this->number($branch, $serial),
                'status' => MemberStatus::Active->value,
                'created_by' => $user->getAuthIdentifier(),
            ])->save();

            $this->syncNominees($member, $data['nominees'] ?? []);

            if (Money::of($data['admission_fee'] ?? null)->isPositive()) {
                $this->takeAdmissionFee($member, Money::of($data['admission_fee']), (int) $data['fee_cash_bank_account_id'], $user);
            }

            return $member;
        });
    }

    /**
     * Books the admission fee taken at registration (cash received, never a deposit):
     *
     *     Dr Cash/Bank    Cr Admission fees (income, member tagged)
     */
    private function takeAdmissionFee(Member $member, Money $fee, int $cashBankAccountId, Authenticatable $user): void
    {
        $cash = CashBankAccount::query()->withoutBranchScope()->with('account')->findOrFail($cashBankAccountId);
        if ((int) $cash->branch_id !== (int) $member->branch_id) {
            throw ValidationException::withMessages(['fee_cash_bank_account_id' => __('The cash or bank account belongs to another branch.')]);
        }
        $income = Account::query()->where('code', config('scms.admission_fee_account'))->firstOrFail();

        try {
            $entry = app(JournalService::class)->record([
                'branch_id' => $member->branch_id,
                'entry_date' => $member->admission_date->toDateString(),
                'description' => __('Admission fee · :member', ['member' => $member->member_no.' '.$member->name]),
                'reference' => $member->member_no,
                'source_type' => 'member_admission',
                'source_id' => $member->id,
                'number_key' => 'admission',
            ], [
                JournalService::debit($cash->account, $fee, ['cash_bank_account_id' => $cash->id, 'member_id' => $member->id, 'component' => LineComponent::Fee]),
                JournalService::credit($income, $fee, ['member_id' => $member->id, 'component' => LineComponent::Fee]),
            ], $user);
        } catch (FinanceException $e) {
            throw ValidationException::withMessages(['admission_fee' => $e->getMessage()]);
        }

        $member->forceFill(['admission_fee' => $fee->toString(), 'admission_fee_entry_id' => $entry->id])->save();
    }

    /** @param  array<string, mixed>  $data */
    public function update(Member $member, array $data, Authenticatable $user): Member
    {
        return DB::transaction(function () use ($member, $data, $user) {
            $member->fill($data)->forceFill(['updated_by' => $user->getAuthIdentifier()])->save();
            $this->syncNominees($member, $data['nominees'] ?? []);

            return $member;
        });
    }

    /** A member is closed only when nothing is open in finance; reopening is always allowed. */
    public function setStatus(Member $member, MemberStatus $status, Authenticatable $user): Member
    {
        return DB::transaction(function () use ($member, $status, $user) {
            $member = Member::withoutBranchScope()->lockForUpdate()->findOrFail($member->id);

            if ($status === MemberStatus::Closed) {
                if ($member->savingsAccounts()->where('status', '!=', SavingsAccountStatus::Closed->value)->exists()) {
                    throw ValidationException::withMessages(['status' => __('Close the member\'s savings accounts first.')]);
                }
                if ($member->loans()->whereIn('status', [LoanStatus::Applied->value, LoanStatus::Approved->value, ...LoanStatus::runningValues()])->exists()) {
                    throw ValidationException::withMessages(['status' => __('The member has a loan that is not finished.')]);
                }
            }

            $member->forceFill([
                'status' => $status->value,
                'closed_on' => $status === MemberStatus::Closed ? today()->toDateString() : null,
                'updated_by' => $user->getAuthIdentifier(),
            ])->save();

            return $member;
        });
    }

    /** Only a member who never had a savings account or loan can be deleted (a mistaken entry). */
    public function delete(Member $member): void
    {
        DB::transaction(function () use ($member) {
            if ($member->savingsAccounts()->exists() || $member->loans()->exists()) {
                throw ValidationException::withMessages(['member' => __('The member has savings accounts or loans; close the member instead.')]);
            }

            $paths = $member->documents()->pluck('path')->all();
            $member->delete();
            DB::afterCommit(fn () => Storage::disk(MemberDocument::DISK)->delete($paths));
        });
    }

    public function addDocument(Member $member, UploadedFile $file, string $type, ?string $title, Authenticatable $user): MemberDocument
    {
        $path = $file->store('members/'.$member->id, MemberDocument::DISK);

        try {
            $document = new MemberDocument(['type' => $type, 'title' => $title]);
            $document->forceFill([
                'member_id' => $member->id,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $user->getAuthIdentifier(),
            ])->save();
        } catch (\Throwable $e) {
            Storage::disk(MemberDocument::DISK)->delete($path);
            throw $e;
        }

        Activity::log('member.document_added', "Added a document to member {$member->member_no}", $member, ['type' => $type]);

        return $document;
    }

    public function deleteDocument(MemberDocument $document): void
    {
        $document->delete();
        Storage::disk(MemberDocument::DISK)->delete($document->path);
        Activity::log('member.document_deleted', "Deleted a document of member {$document->member->member_no}", $document->member, ['type' => $document->type]);
    }

    /** DHK-000123: the branch code (or its id) and the serial. */
    private function number(Branch $branch, int $serial): string
    {
        $prefix = strtoupper((string) ($branch->code ?: 'B'.$branch->id));

        return $prefix.'-'.str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
    }

    /** @param  list<array<string, mixed>>  $nominees */
    private function syncNominees(Member $member, array $nominees): void
    {
        $member->nominees()->delete();

        foreach ($nominees as $nominee) {
            if (filled($nominee['name'] ?? null)) {
                $member->nominees()->create($nominee);
            }
        }
    }
}
