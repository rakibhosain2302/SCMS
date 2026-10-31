@extends('nit::print')
@section('title', __('Member statement'))
@section('subtitle', $member->member_no.' · '.$member->name)
@section('meta')
    <small>{{ $member->branch?->name }} · {{ nit_date($from) }} – {{ nit_date($to) }}</small>
@endsection

@section('content')
@php($money = fn ($m) => $m->format())
<p><strong>{{ __('Mobile') }}:</strong> {{ $member->phone }} &nbsp; <strong>{{ __('Admitted') }}:</strong> {{ nit_date($member->admission_date) }} &nbsp; <strong>{{ __('Shares held') }}:</strong> {{ $shares->format() }}</p>

@foreach ($savings as $s)
    <h3>{{ __('Savings') }} · {{ $s['account']->account_number }} · {{ $s['account']->scheme?->name }}</h3>
    <table>
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Number') }}</th><th>{{ __('Description') }}</th><th style="text-align:right">{{ __('Withdrawn') }}</th><th style="text-align:right">{{ __('Deposited') }}</th><th style="text-align:right">{{ __('Balance') }}</th></tr></thead>
        <tbody>
            <tr><td colspan="5">{{ __('Brought forward') }}</td><td style="text-align:right">{{ $s['opening']->format() }}</td></tr>
            @foreach ($s['rows'] as $r)
                <tr><td>{{ nit_date($r->entry_date) }}</td><td>{{ $r->number }}</td><td>{{ $r->description }}</td>
                    <td style="text-align:right">{{ (float) $r->debit ? nit_money($r->debit) : '' }}</td><td style="text-align:right">{{ (float) $r->credit ? nit_money($r->credit) : '' }}</td>
                    <td style="text-align:right">{{ $r->balance->format() }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="5">{{ __('Balance') }}</th><th style="text-align:right">{{ $s['closing']->format() }}</th></tr></tfoot>
    </table>
@endforeach

@foreach ($loans as $l)
    <h3>{{ __('Loan') }} · {{ $l['loan']->loan_number }} · {{ __('principal :amount', ['amount' => \SCMS\Finance\Support\Money::of($l['loan']->principal)->format()]) }}</h3>
    <table>
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Number') }}</th><th>{{ __('Description') }}</th><th style="text-align:right">{{ __('Lent') }}</th><th style="text-align:right">{{ __('Repaid') }}</th><th style="text-align:right">{{ __('Principal owed') }}</th></tr></thead>
        <tbody>
            <tr><td colspan="5">{{ __('Brought forward') }}</td><td style="text-align:right">{{ $l['opening']->format() }}</td></tr>
            @foreach ($l['rows'] as $r)
                <tr><td>{{ nit_date($r->entry_date) }}</td><td>{{ $r->number }}</td><td>{{ $r->description }}</td>
                    <td style="text-align:right">{{ (float) $r->debit ? nit_money($r->debit) : '' }}</td><td style="text-align:right">{{ (float) $r->credit ? nit_money($r->credit) : '' }}</td>
                    <td style="text-align:right">{{ $r->balance->format() }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="5">{{ __('Principal owed') }}</th><th style="text-align:right">{{ $l['closing']->format() }}</th></tr></tfoot>
    </table>
@endforeach

@if ($savings->isEmpty() && $loans->isEmpty())
    <p>{{ __('No savings accounts or loans.') }}</p>
@endif
@endsection
