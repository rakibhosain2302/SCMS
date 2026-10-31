@extends('nit::master')
@section('title', __('Members'))

@push('buttons')
@can('member_create')
<a href="{{ route('members.create') }}" class="btn btn-sm btn-nit fw-bold"><i class="fas fa-user-plus" aria-hidden="true"></i> {{ __('Register member') }}</a>
<a href="{{ route('members.import') }}" class="btn btn-sm btn-light fw-bold"><i class="fas fa-file-import" aria-hidden="true"></i> {{ __('Import') }}</a>
@endcan
@endpush

@section('content')
<div class="nx-page">
    <x-nit::table :rows="$members" :title="__('Members')" search row-actions
        :columns="['member_no' => __('Member no.'), 'name' => __('Name'), 'phone' => __('Phone'), 'branch' => __('Branch'), 'admission_date' => __('Admitted'), 'status' => __('Status')]"
        :sortable="['member_no', 'name', 'admission_date']" default-sort="-id"
        :empty="__('No members yet')" empty-icon="fas fa-users">
        <x-slot:filters>
            <select name="status" class="form-select nx-filter-select" aria-label="{{ __('Status') }}">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach
            </select>
            @if (count($branches) > 1)
                <select name="branch_id" class="form-select nx-filter-select" aria-label="{{ __('Branch') }}">
                    <option value="">{{ __('All branches') }}</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}" @selected((int) request('branch_id') === $id)>{{ $name }}</option>@endforeach
                </select>
            @endif
            <select name="gender" class="form-select nx-filter-select" aria-label="{{ __('Gender') }}">
                <option value="">{{ __('All genders') }}</option>
                @foreach (['male' => __('Male'), 'female' => __('Female'), 'other' => __('Other')] as $v => $l)<option value="{{ $v }}" @selected(request('gender') === $v)>{{ $l }}</option>@endforeach
            </select>
        </x-slot:filters>
        @foreach ($members as $m)
            <tr>
                <td><a href="{{ route('members.show', $m) }}" class="fw-semibold">{{ $m->member_no }}</a></td>
                <td>{{ $m->name }}@if ($m->name_bn)<span class="nx-muted fs-7 d-block">{{ $m->name_bn }}</span>@endif</td>
                <td>{{ $m->phone }}</td>
                <td>{{ $m->branch?->name }}</td>
                <td>{{ nit_date($m->admission_date) }}</td>
                <td><x-nit::badge :tone="$m->status->tone()">{{ $m->status->label() }}</x-nit::badge></td>
                <td class="text-end">
                    <a href="{{ route('members.show', $m) }}" class="nx-icon-btn" title="{{ __('Open') }}" aria-label="{{ __('Open :name', ['name' => $m->name]) }}"><i class="fas fa-eye" aria-hidden="true"></i></a>
                    @can('member_edit')
                        <a href="{{ route('members.edit', $m) }}" class="nx-icon-btn" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $m->name]) }}"><i class="fas fa-pen" aria-hidden="true"></i></a>
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-nit::table>
</div>
@endsection
