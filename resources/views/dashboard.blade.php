@extends('nit::master')
@section('title', __('Dashboard'))
@include('finance::partials.styles')

@section('content')
<div class="nx-page">
    @if ($members === null && $summary === null)
        <x-nit::card :title="__('Welcome, :name', ['name' => auth()->user()->name])">
            {{ __('You are signed in.') }}
        </x-nit::card>
    @endif

    @if ($members !== null)
        <x-nit::card :title="__('Members')">
            <x-slot:actions>
                @can('member_create')<x-nit::button variant="light" :href="route('members.create')" icon="fas fa-user-plus">{{ __('Register member') }}</x-nit::button>@endcan
            </x-slot:actions>
            <div class="fin-tiles">
                <a href="{{ route('members.index') }}" class="fin-tile text-reset"><small>{{ __('Total members') }}</small><strong>{{ nit_number($members['total']) }}</strong></a>
                <a href="{{ route('members.index', ['status' => 'active']) }}" class="fin-tile text-reset"><small>{{ __('Active members') }}</small><strong>{{ nit_number($members['active']) }}</strong></a>
                <div class="fin-tile"><small>{{ __('Joined this month') }}</small><strong>{{ nit_number($members['new']) }}</strong></div>
            </div>
        </x-nit::card>
    @endif

    @if ($summary !== null)
        @php($t = $summary['today'])
        <x-nit::card :title="__('Today')" :subtitle="nit_date(today(), 'l, j F Y')" class="mt-5">
            <div class="fin-tiles">
                @foreach ([
                    'deposits' => __('Savings collected'), 'withdrawals' => __('Savings withdrawn'), 'principal_repaid' => __('Loan principal repaid'),
                    'interest' => __('Loan interest'), 'disbursed' => __('Loans disbursed'), 'income' => __('Income'), 'expense' => __('Expense'),
                ] as $key => $label)
                    <div class="fin-tile"><small>{{ $label }}</small><strong>{{ $t[$key]->format() }}</strong></div>
                @endforeach
            </div>
        </x-nit::card>

        <x-nit::card :title="__('Balances')" :subtitle="__('From posted entries')" class="mt-5">
            <div class="fin-tiles">
                <div class="fin-tile"><small>{{ __('Cash in hand') }}</small><strong>{{ $summary['cash']->format() }}</strong></div>
                <div class="fin-tile"><small>{{ __('Bank') }}</small><strong>{{ $summary['bank']->format() }}</strong></div>
                <div class="fin-tile"><small>{{ __('Member savings') }}</small><strong>{{ $summary['savings']->format() }}</strong></div>
                <div class="fin-tile"><small>{{ __('Loans outstanding') }}</small><strong>{{ $summary['loans']->format() }}</strong></div>
                <div class="fin-tile"><small>{{ __('Overdue') }}</small><strong @class(['fin-neg' => $summary['overdue']->isPositive()])>{{ $summary['overdue']->format() }}</strong>
                    <span class="nx-muted fs-7">{{ trans_choice(':count loan|:count loans', $summary['overdue_loans'], ['count' => $summary['overdue_loans']]) }}</span></div>
            </div>
        </x-nit::card>
    @endif

    @if ($branches !== null && $branches->isNotEmpty())
        <x-nit::card :title="__('Branches')" class="mt-5" :padding="false">
            <div class="table-responsive"><table class="nx-tbl">
                <thead><tr><th>{{ __('Branch') }}</th>@if ($members !== null)<th class="fin-num">{{ __('Active members') }}</th>@endif<th class="fin-num">{{ __('Cash') }}</th><th class="fin-num">{{ __('Bank') }}</th><th class="fin-num">{{ __('Savings') }}</th><th class="fin-num">{{ __('Loans outstanding') }}</th></tr></thead>
                <tbody>
                    @foreach ($branches as $b)
                        <tr>
                            <td>{{ $b['name'] }}</td>
                            @if ($members !== null)<td class="fin-num">{{ nit_number($b['members']) }}</td>@endif
                            @foreach (['cash', 'bank', 'savings', 'loans'] as $key)
                                <td class="fin-num">{{ $b['balances'] ? $b['balances'][$key]->format() : '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-nit::card>
    @endif

    @if ($summary !== null)
        <div class="d-flex flex-wrap gap-2 mt-5">
            @can(\SCMS\Finance\Support\Abilities::SAVINGS_DEPOSIT)<x-nit::button :href="route('finance.savings-transactions.create', ['type' => 'deposit'])" icon="fas fa-plus">{{ __('Deposit') }}</x-nit::button>@endcan
            @can(\SCMS\Finance\Support\Abilities::LOAN_REPAY)<x-nit::button variant="light" :href="route('finance.loans.index', ['status' => 'active'])" icon="fas fa-hand-holding-usd">{{ __('Take repayment') }}</x-nit::button>@endcan
            @can(\SCMS\Finance\Support\Abilities::REPORT_VIEW)<x-nit::button variant="light" :href="route('finance.reports.cashbook')" icon="fas fa-book-open">{{ __('Cash book') }}</x-nit::button>@endcan
            @can(\SCMS\Finance\Support\Abilities::REPORT_VIEW)<x-nit::button variant="light" :href="route('finance.reports.loans', ['overdue' => 1])" icon="fas fa-exclamation-triangle">{{ __('Overdue loans') }}</x-nit::button>@endcan
        </div>
    @endif
</div>
@endsection
