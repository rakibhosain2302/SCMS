@extends('nit::master')
@section('title', $member->member_no.' · '.$member->name)
@include('finance::partials.styles')

@push('buttons')
@can('member_edit')
<a href="{{ route('members.edit', $member) }}" class="btn btn-sm btn-light fw-bold"><i class="fas fa-pen" aria-hidden="true"></i> {{ __('Edit') }}</a>
@endcan
<button type="button" class="btn btn-sm btn-light fw-bold" onclick="window.print()"><i class="fas fa-print" aria-hidden="true"></i> {{ __('Print') }}</button>
<a href="{{ route('members.statement', $member) }}" target="_blank" rel="noopener" class="btn btn-sm btn-light fw-bold"><i class="fas fa-file-invoice" aria-hidden="true"></i> {{ __('Statement') }}</a>
@endpush

@section('content')
<div class="nx-page">
    <x-nit::card :title="$member->name" :subtitle="$member->member_no.' · '.$member->branch?->name">
        <x-slot:actions><x-nit::badge :tone="$member->status->tone()">{{ $member->status->label() }}</x-nit::badge></x-slot:actions>
        <div class="d-flex flex-wrap gap-5">
            @if ($photo)
                <img src="{{ route('members.documents.show', [$member, $photo]) }}" alt="{{ __('Photo of :name', ['name' => $member->name]) }}" class="rounded" style="width:110px;height:130px;object-fit:cover">
            @endif
            <dl class="fin-facts flex-grow-1">
                @if ($member->name_bn)<dt>{{ __('Name in Bangla') }}</dt><dd>{{ $member->name_bn }}</dd>@endif
                <dt>{{ __('Father\'s name') }}</dt><dd>{{ $member->father_name ?: '—' }}</dd>
                <dt>{{ __('Mother\'s name') }}</dt><dd>{{ $member->mother_name ?: '—' }}</dd>
                @if ($member->spouse_name)<dt>{{ __('Spouse\'s name') }}</dt><dd>{{ $member->spouse_name }}</dd>@endif
                <dt>{{ __('NID') }}</dt><dd>{{ $member->nid ?: '—' }}</dd>
                <dt>{{ __('Date of birth') }}</dt><dd>{{ $member->date_of_birth ? nit_date($member->date_of_birth) : '—' }}</dd>
                <dt>{{ __('Gender') }}</dt><dd>{{ $member->gender ? __(ucfirst($member->gender)) : '—' }}</dd>
                <dt>{{ __('Mobile') }}</dt><dd>{{ $member->phone }}</dd>
                <dt>{{ __('E-mail') }}</dt><dd>{{ $member->email ?: '—' }}</dd>
                <dt>{{ __('Occupation') }}</dt><dd>{{ $member->occupation ?: '—' }}</dd>
                <dt>{{ __('Admitted') }}</dt><dd>{{ nit_date($member->admission_date) }}</dd>
                @if ($member->closed_on)<dt>{{ __('Closed') }}</dt><dd>{{ nit_date($member->closed_on) }}</dd>@endif
                <dt>{{ __('Present address') }}</dt><dd>{{ $member->present_address }}</dd>
                <dt>{{ __('Permanent address') }}</dt><dd>{{ $member->permanent_address ?: '—' }}</dd>
                <dt>{{ __('Registered by') }}</dt><dd>{{ $member->owner?->name ?? '—' }}</dd>
                @if ($member->note)<dt>{{ __('Note') }}</dt><dd>{{ $member->note }}</dd>@endif
            </dl>
        </div>
        @can('member_edit')
            <x-slot:footer>
                <form method="POST" action="{{ route('members.status', $member) }}" class="d-inline-flex gap-2 fin-no-print" data-nx-confirm="{{ __('Change the member\'s status?') }}" data-nx-confirm-button="{{ __('Change') }}">
                    @csrf
                    <select name="status" class="form-select form-select-sm" aria-label="{{ __('Status') }}">
                        @foreach ($statuses as $v => $l)@if ($v !== $member->status->value)<option value="{{ $v }}">{{ $l }}</option>@endif @endforeach
                    </select>
                    <button type="submit" class="nx-btn nx-btn-light">{{ __('Set status') }}</button>
                </form>
                @can('member_delete')
                    @if ($accounts->isEmpty() && $loans->isEmpty())
                        <x-nit::delete-button :action="route('members.destroy', $member)" :name="$member->name" />
                    @endif
                @endcan
            </x-slot:footer>
        @endcan
    </x-nit::card>

    <div class="fin-tiles mt-5">
        <div class="fin-tile"><small>{{ __('Savings balance') }}</small><strong>{{ $totals['savings']->format() }}</strong></div>
        <div class="fin-tile"><small>{{ __('Loan outstanding (principal)') }}</small><strong>{{ $totals['loans']->format() }}</strong></div>
        <div class="fin-tile"><small>{{ __('Overdue') }}</small><strong @class(['fin-neg' => $totals['overdue']->isPositive()])>{{ $totals['overdue']->format() }}</strong></div>
        <div class="fin-tile"><small>{{ __('Running loans') }}</small><strong>{{ nit_number($totals['running']) }}</strong></div>
        <div class="fin-tile"><small>{{ __('Shares held') }}</small><strong>{{ $shares->format() }}</strong>
            @if ($member->isActive())@can('finance_share_manage')<a href="{{ route('finance.shares.create', ['branch_id' => $member->branch_id, 'member_q' => $member->member_no, 'member_id' => $member->id]) }}" class="d-block fs-7 mt-1">{{ __('Sell shares') }}</a>@endcan @endif</div>
        @if ($member->admission_fee)<div class="fin-tile"><small>{{ __('Admission fee paid') }}</small><strong>{{ nit_money($member->admission_fee) }}</strong></div>@endif
    </div>

    <x-nit::card :title="__('Savings accounts')" class="mt-5" :padding="false">
        <x-slot:actions>
            @if ($member->isActive())
                @can('finance_savings_manage')<x-nit::button variant="light" :href="route('finance.savings-accounts.create', ['branch_id' => $member->branch_id, 'member_q' => $member->member_no])" icon="fas fa-plus">{{ __('Open account') }}</x-nit::button>@endcan
            @endif
        </x-slot:actions>
        @if ($accounts->isEmpty())
            <x-nit::empty-state icon="fas fa-piggy-bank" :title="__('No savings accounts')" />
        @else
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Scheme') }}</th><th>{{ __('Opened') }}</th><th>{{ __('Status') }}</th><th class="fin-num">{{ __('Balance') }}</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $a)
                        <tr>
                            <td>@can('finance_savings_view')<a href="{{ route('finance.savings-accounts.show', $a) }}">{{ $a->account_number }}</a>@else{{ $a->account_number }}@endcan</td>
                            <td>{{ $a->scheme?->name }}</td>
                            <td>{{ nit_date($a->opened_on) }}</td>
                            <td><x-nit::badge :tone="$a->status->tone()">{{ $a->status->label() }}</x-nit::badge></td>
                            <td class="fin-num">{{ ($savings[$a->id] ?? \SCMS\Finance\Support\Money::zero())->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </x-nit::card>

    <x-nit::card :title="__('Loans')" class="mt-5" :padding="false">
        <x-slot:actions>
            @if ($member->isActive())
                @can('finance_loan_create')<x-nit::button variant="light" :href="route('finance.loans.create', ['branch_id' => $member->branch_id, 'member_q' => $member->member_no])" icon="fas fa-plus">{{ __('Loan application') }}</x-nit::button>@endcan
            @endif
        </x-slot:actions>
        @if ($loans->isEmpty())
            <x-nit::empty-state icon="fas fa-hand-holding-usd" :title="__('No loans')" />
        @else
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>{{ __('Loan') }}</th><th>{{ __('Product') }}</th><th>{{ __('Disbursed') }}</th><th>{{ __('Status') }}</th><th class="fin-num">{{ __('Principal') }}</th><th class="fin-num">{{ __('Outstanding') }}</th></tr></thead>
                <tbody>
                    @foreach ($loans as $l)
                        <tr>
                            <td>@can('finance_loan_view')<a href="{{ route('finance.loans.show', $l) }}">{{ $l->loan_number }}</a>@else{{ $l->loan_number }}@endcan</td>
                            <td>{{ $l->product?->name }}</td>
                            <td>{{ $l->disbursed_on ? nit_date($l->disbursed_on) : '—' }}</td>
                            <td><x-nit::badge :tone="$l->status->tone()">{{ $l->status->label() }}</x-nit::badge></td>
                            <td class="fin-num">@finmoney($l->principal)</td>
                            <td class="fin-num">{{ ($outstanding[$l->id] ?? \SCMS\Finance\Support\Money::zero())->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </x-nit::card>

    <x-nit::card :title="__('Transaction history')" :subtitle="__('Posted entries on this member\'s savings and loans')" class="mt-5" :padding="false">
        @if ($entries->isEmpty())
            <x-nit::empty-state icon="fas fa-exchange-alt" :title="__('No transactions yet')" />
        @else
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Number') }}</th><th>{{ __('Description') }}</th><th>{{ __('Status') }}</th><th class="fin-num">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($entries as $e)
                        <tr>
                            <td>{{ nit_date($e->entry_date) }}</td>
                            <td>@can('finance_journal_view')<a href="{{ route('finance.journals.show', $e) }}">{{ $e->number }}</a>@else{{ $e->number }}@endcan</td>
                            <td>{{ $e->description }}</td>
                            <td>@include('finance::partials.status', ['status' => $e->status])</td>
                            <td class="fin-num">@finmoney($e->total)</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="p-4">{{ $entries->links() }}</div>
        @endif
    </x-nit::card>

    <div class="row g-5 mt-0">
        <div class="col-lg-6">
            <x-nit::card :title="__('Nominees')" :padding="false">
                @if ($member->nominees->isEmpty())
                    <x-nit::empty-state icon="fas fa-user-friends" :title="__('No nominees')" />
                @else
                    <div class="table-responsive"><table class="table align-middle mb-0">
                        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Relation') }}</th><th>{{ __('Mobile') }}</th><th class="fin-num">{{ __('Share') }}</th></tr></thead>
                        <tbody>
                            @foreach ($member->nominees as $n)
                                <tr><td>{{ $n->name }}@if ($n->nid)<span class="nx-muted fs-7 d-block">NID {{ $n->nid }}</span>@endif</td><td>{{ $n->relation }}</td><td>{{ $n->phone ?: '—' }}</td><td class="fin-num">{{ nit_number($n->share, 2) }}%</td></tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-nit::card>
        </div>
        <div class="col-lg-6">
            <x-nit::card :title="__('Documents')" :padding="false">
                @if ($member->documents->isEmpty())
                    <x-nit::empty-state icon="fas fa-file-alt" :title="__('No documents')" />
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($member->documents as $d)
                            <li class="list-group-item d-flex align-items-center gap-3">
                                <i class="fas {{ $d->isImage() ? 'fa-file-image' : 'fa-file-pdf' }} text-muted" aria-hidden="true"></i>
                                <a href="{{ route('members.documents.show', [$member, $d]) }}" target="_blank" rel="noopener" class="flex-grow-1">{{ $documentTypes[$d->type] ?? $d->type }}{{ $d->title ? ' · '.$d->title : '' }}</a>
                                <span class="nx-muted fs-7">{{ nit_date($d->created_at) }}</span>
                                @can('member_edit')
                                    <form method="POST" action="{{ route('members.documents.destroy', [$member, $d]) }}" data-nx-confirm="{{ __('Delete this document?') }}" data-nx-confirm-button="{{ __('Delete') }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="nx-icon-btn" title="{{ __('Delete') }}" aria-label="{{ __('Delete document') }}"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                    </form>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
                @can('member_edit')
                    <form method="POST" action="{{ route('members.documents.store', $member) }}" enctype="multipart/form-data" class="p-4 border-top d-flex flex-wrap gap-2 align-items-end fin-no-print">
                        @csrf
                        <select name="type" class="form-select form-select-sm" style="max-width:10rem" aria-label="{{ __('Document type') }}">
                            @foreach ($documentTypes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                        <input type="text" name="title" class="form-control form-control-sm" style="max-width:12rem" placeholder="{{ __('Title (optional)') }}" aria-label="{{ __('Title') }}">
                        <input type="file" name="file" class="form-control form-control-sm" style="max-width:16rem" accept=".pdf,.jpg,.jpeg,.png,.webp" required aria-label="{{ __('File') }}">
                        <button type="submit" class="nx-btn nx-btn-light"><i class="fas fa-upload" aria-hidden="true"></i> {{ __('Upload') }}</button>
                    </form>
                @endcan
            </x-nit::card>
        </div>
    </div>
</div>
@endsection
