<?php

namespace App\Http\Controllers\Members;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRequest;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Services\MemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Branches;
use Nit\Master\Support\CsvImport;
use Nit\Master\Support\Table;
use SCMS\Finance\Models\CashBankAccount;
use SCMS\Finance\Models\JournalEntry;
use SCMS\Finance\Models\LoanInstallment;
use SCMS\Finance\Services\LedgerService;
use SCMS\Finance\Services\ShareService;
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
        return view('members.form', [
            'member' => new Member(['admission_date' => today()]),
            'branches' => $this->branchOptions($request),
            'feeAccounts' => $request->user()->can('member_fee_collect') ? CashBankAccount::query()->active()->with('branch:id,name')->orderBy('name')->get()
                ->mapWithKeys(fn ($a) => [$a->id => $a->branch?->name.' · '.$a->label()])->all() : null,
        ]);
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
            'shares' => rescue(fn () => app(ShareService::class)->balance($member->id), Money::zero(), false),
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

    /** CSV columns of the member import; branch_code picks one of the user's branches. */
    public const IMPORT_COLUMNS = [
        'branch_code' => 'branch_code', 'member_no' => 'member_no', 'name' => 'name', 'name_bn' => 'name_bn', 'father_name' => 'father_name',
        'mother_name' => 'mother_name', 'spouse_name' => 'spouse_name', 'gender' => 'gender', 'date_of_birth' => 'date_of_birth', 'nid' => 'nid',
        'phone' => 'phone', 'email' => 'email', 'occupation' => 'occupation', 'present_address' => 'present_address',
        'permanent_address' => 'permanent_address', 'admission_date' => 'admission_date',
    ];

    #[Permission('member_create', 'Register members')]
    public function importForm()
    {
        return view('members.import', ['columns' => array_keys(self::IMPORT_COLUMNS), 'report' => session('import_report')]);
    }

    #[Permission('member_create', 'Register members')]
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $branches = Branches::forUser($request->user())->mapWithKeys(fn ($b) => [strtoupper((string) $b->code) => $b->id]);
        $rules = collect((new MemberRequest())->setUserResolver(fn () => $request->user())->rules())
            ->only(['member_no', 'name', 'name_bn', 'father_name', 'mother_name', 'spouse_name', 'gender', 'date_of_birth', 'nid', 'phone', 'email', 'occupation', 'present_address', 'permanent_address', 'admission_date'])
            ->put('branch_id', ['required', 'integer', 'in:'.$branches->implode(',')])->all();

        $report = CsvImport::run($request->file('file')->getRealPath(), self::IMPORT_COLUMNS, $rules,
            fn (array $data) => $this->members->create($data, $request->user()),
            $request->boolean('dry_run'),
            fn (array $data) => ['branch_id' => $branches[strtoupper((string) ($data['branch_code'] ?? ''))] ?? null] + collect($data)->except('branch_code')->all(),
        );

        return redirect()->route('members.import')->with('import_report', $report);
    }

    /** A printable statement: each savings account and loan with its postings and running balance. */
    #[Permission('member_view', 'View members')]
    public function statement(Request $request, Member $member, LedgerService $ledger)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfYear();

        $book = function (string $column, int $id, ?string $component, bool $credit) use ($ledger, $from, $to) {
            $narrow = fn ($q) => $q->where("l.$column", $id)->when($component, fn ($q) => $q->where('l.component', $component));
            $sign = fn (Money $net) => $credit ? $net->negated() : $net;
            $balance = $sign($ledger->net($narrow($ledger->lines(null, null, $from->copy()->subDay()))));
            $opening = $balance;
            $rows = $narrow($ledger->lines(null, $from, $to))->orderBy('e.entry_date')->orderBy('e.id')
                ->get(['l.debit', 'l.credit', 'e.number', 'e.entry_date', 'e.description'])
                ->map(function ($row) use (&$balance, $sign) {
                    $balance = $balance->plus($sign(Money::of((string) $row->debit)->minus((string) $row->credit)));
                    $row->balance = $balance;

                    return $row;
                });

            return ['opening' => $opening, 'rows' => $rows, 'closing' => $balance];
        };

        return view('members.statement', [
            'member' => $member->load('branch'),
            'from' => $from,
            'to' => $to,
            'savings' => $member->savingsAccounts()->with('scheme:id,name')->orderBy('id')->get()
                ->map(fn ($a) => ['account' => $a] + $book('savings_account_id', $a->id, null, true)),
            'loans' => $member->loans()->whereNotNull('disbursed_on')->orderBy('id')->get()
                ->map(fn ($l) => ['loan' => $l] + $book('loan_id', $l->id, 'principal', false)),
            'shares' => rescue(fn () => app(ShareService::class)->balance($member->id), Money::zero(), false),
        ]);
    }

    /** @return array<int, string> */
    private function branchOptions(Request $request): array
    {
        return Branches::forUser($request->user())->pluck('name', 'id')->all();
    }
}
