<?php

namespace App\Http\Controllers\Members;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRequest;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Services\MemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Branches;
use Nit\Master\Support\Table;
use SCMS\Finance\Models\JournalEntry;
use SCMS\Finance\Models\LoanInstallment;
use SCMS\Finance\Services\LedgerService;
use SCMS\Finance\Support\Money;

class MemberController extends Controller
{
    public function __construct(private readonly MemberService $members)
    {
    }

    #[Permission('member_view', 'View members')]
    public function index(Request $request)
    {
        $query = Member::query()->with('branch:id,name')
            ->when(MemberStatus::tryFrom((string) $request->query('status')), fn ($q, $s) => $q->where('status', $s->value))
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('gender'), fn ($q, $g) => $q->where('gender', $g));

        $members = Table::make($query, $request)
            ->search(['member_no', 'name', 'name_bn', 'phone', 'nid', 'father_name'])
            ->sortable(['member_no', 'name', 'admission_date'], '-id')
            ->paginate(25);

        return view('members.index', [
            'members' => $members,
            'statuses' => MemberStatus::options(),
            'branches' => Branches::forUser($request->user())->pluck('name', 'id')->all(),
        ]);
    }

    #[Permission('member_create', 'Register members')]
    public function create(Request $request)
    {
        return view('members.form', ['member' => new Member(['admission_date' => today()]), 'branches' => $this->branchOptions($request)]);
    }

    #[Permission('member_create', 'Register members')]
    public function store(MemberRequest $request)
    {
        $member = $this->members->create($request->validated(), $request->user());

        return redirect()->route('members.show', $member)->with('success', __('Member :no registered.', ['no' => $member->member_no]));
    }

    #[Permission('member_view', 'View members')]
    public function show(Member $member, LedgerService $ledger)
    {
        $member->load(['branch', 'nominees', 'documents.uploader', 'owner']);
        $accounts = $member->savingsAccounts()->with('scheme:id,name')->orderBy('opened_on')->get();
        $loans = $member->loans()->with('product:id,name')->orderByDesc('id')->get();

        $savings = collect($ledger->netBy('savings_account_id', $accounts->pluck('id')->all()))->map(fn (Money $net) => $net->negated());
        $outstanding = $ledger->netBy('loan_id', $loans->pluck('id')->all(), null, 'principal');
        $overdue = Money::sum(LoanInstallment::query()->whereIn('loan_id', $loans->pluck('id'))->overdue()->get()->map->remaining()->all());

        // Every posted or reversed entry that touched this member's ledgers, newest first.
        $entries = JournalEntry::query()->withoutBranchScope()
            ->whereIn('id', fn ($q) => $q->select('journal_entry_id')->from('finance_journal_lines')->where('member_id', $member->id))
            ->whereIn('status', ['posted', 'reversed'])
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(15, ['*'], 'history');

        return view('members.show', [
            'member' => $member,
            'accounts' => $accounts,
            'loans' => $loans,
            'savings' => $savings,
            'outstanding' => $outstanding,
            'totals' => [
                'savings' => Money::sum($savings->values()->all()),
                'loans' => Money::sum(array_values($outstanding)),
                'overdue' => $overdue,
                'running' => $loans->filter(fn ($l) => $l->status->isRunning())->count(),
            ],
            'entries' => $entries,
            'statuses' => MemberStatus::options(),
            'documentTypes' => MemberDocument::typeLabels(),
            'photo' => $member->documents->firstWhere('type', 'photo'),
        ]);
    }

    #[Permission('member_edit', 'Change members')]
    public function edit(Request $request, Member $member)
    {
        return view('members.form', ['member' => $member->load('nominees'), 'branches' => $this->branchOptions($request)]);
    }

    #[Permission('member_edit', 'Change members')]
    public function update(MemberRequest $request, Member $member)
    {
        $this->members->update($member, $request->validated(), $request->user());

        return redirect()->route('members.show', $member)->with('success', __('Member updated.'));
    }

    #[Permission('member_edit', 'Change members')]
    public function status(Request $request, Member $member)
    {
        $data = $request->validate(['status' => ['required', Rule::enum(MemberStatus::class)]]);
        $this->members->setStatus($member, MemberStatus::from($data['status']), $request->user());

        return back()->with('success', __('Status changed.'));
    }

    #[Permission('member_delete', 'Delete members')]
    public function destroy(Member $member)
    {
        $this->members->delete($member);

        return redirect()->route('members.index')->with('success', __('Member deleted.'));
    }

    #[Permission('member_edit', 'Change members')]
    public function storeDocument(Request $request, Member $member)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(MemberDocument::TYPES)],
            'title' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:4096', $request->input('type') === 'photo' || $request->input('type') === 'signature' ? 'mimes:jpg,jpeg,png,webp' : 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        $this->members->addDocument($member, $request->file('file'), $data['type'], $data['title'] ?? null, $request->user());

        return back()->with('success', __('Document uploaded.'));
    }

    #[Permission('member_view', 'View members')]
    public function document(Member $member, MemberDocument $document)
    {
        abort_unless($document->member_id === $member->id, 404);
        abort_unless(Storage::disk(MemberDocument::DISK)->exists($document->path), 404);

        return Storage::disk(MemberDocument::DISK)->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    #[Permission('member_edit', 'Change members')]
    public function destroyDocument(Member $member, MemberDocument $document)
    {
        abort_unless($document->member_id === $member->id, 404);
        $this->members->deleteDocument($document);

        return back()->with('success', __('Document deleted.'));
    }

    /** @return array<int, string> */
    private function branchOptions(Request $request): array
    {
        return Branches::forUser($request->user())->pluck('name', 'id')->all();
    }
}
