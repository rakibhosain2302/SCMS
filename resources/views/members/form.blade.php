@extends('nit::master')

@php
    $editing = $member->exists;
    $nominees = old('nominees', $member->nominees?->map(fn ($n) => $n->only(['name', 'relation', 'nid', 'phone', 'share', 'address']) + ['date_of_birth' => $n->date_of_birth?->toDateString()])->all() ?? []);
    if ($nominees === []) {
        $nominees = [['share' => 100]];
    }
@endphp
@section('title', $editing ? __('Edit :name', ['name' => $member->name]) : __('Register member'))
@section('nx-inline-errors', '1')

@section('content')
<div class="nx-page">
    <form method="POST" action="{{ $editing ? route('members.update', $member) : route('members.store') }}" data-nx-once novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-nit::card :title="$editing ? $member->member_no.' · '.$member->name : __('Register member')" :subtitle="__('Personal details')">
            <div class="nx-form-grid">
                @unless ($editing)
                    <x-nit::select name="branch_id" :label="__('Branch')" :options="$branches" :value="count($branches) === 1 ? array_key_first($branches) : null" required :placeholder="__('Choose…')" />
                    <x-nit::input name="member_no" :label="__('Member no.')" :hint="__('Leave empty for the next number of the branch.')" />
                @endunless
                <x-nit::input name="name" :label="__('Name')" :value="$member->name" required />
                <x-nit::input name="name_bn" :label="__('Name in Bangla')" :value="$member->name_bn" />
                <x-nit::input name="father_name" :label="__('Father\'s name')" :value="$member->father_name" />
                <x-nit::input name="mother_name" :label="__('Mother\'s name')" :value="$member->mother_name" />
                <x-nit::input name="spouse_name" :label="__('Spouse\'s name')" :value="$member->spouse_name" />
                <x-nit::select name="gender" :label="__('Gender')" :options="['male' => __('Male'), 'female' => __('Female'), 'other' => __('Other')]" :value="$member->gender" :placeholder="__('Choose…')" />
                <x-nit::input name="date_of_birth" type="date" :label="__('Date of birth')" :value="$member->date_of_birth?->toDateString()" />
                <x-nit::input name="nid" :label="__('NID number')" :value="$member->nid" :hint="__('10, 13 or 17 digits')" inputmode="numeric" />
                <x-nit::input name="occupation" :label="__('Occupation')" :value="$member->occupation" />
                <x-nit::input name="admission_date" type="date" :label="__('Admission date')" :value="$member->admission_date?->toDateString()" required />
            </div>
        </x-nit::card>

        <x-nit::card :title="__('Contact and address')" class="mt-5">
            <div class="nx-form-grid">
                <x-nit::input name="phone" :label="__('Mobile')" :value="$member->phone" required inputmode="tel" placeholder="01XXXXXXXXX" />
                <x-nit::input name="email" type="email" :label="__('E-mail')" :value="$member->email" />
                <x-nit::textarea name="present_address" :label="__('Present address')" :value="$member->present_address" rows="2" required />
                <x-nit::textarea name="permanent_address" :label="__('Permanent address')" :value="$member->permanent_address" rows="2" />
                <x-nit::textarea name="note" :label="__('Note')" :value="$member->note" rows="2" wrapper-class="nx-full" />
            </div>
        </x-nit::card>

        <x-nit::card :title="__('Nominees')" :subtitle="__('Shares must add up to 100%. Leave the name empty to remove a row.')" class="mt-5">
            @error('nominees') <div class="nx-alert mb-4" role="alert"><i class="fas fa-exclamation-circle mt-1" aria-hidden="true"></i> <span>{{ $message }}</span></div> @enderror
            <div class="table-responsive">
                <table class="table align-middle mb-0" data-nominees>
                    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Relation') }}</th><th>{{ __('NID') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Date of birth') }}</th><th style="width:7rem">{{ __('Share %') }}</th><th>{{ __('Address') }}</th></tr></thead>
                    <tbody>
                        @foreach ($nominees as $i => $n)
                            <tr>
                                @foreach (['name' => 'text', 'relation' => 'text', 'nid' => 'text', 'phone' => 'text', 'date_of_birth' => 'date', 'share' => 'number', 'address' => 'text'] as $field => $type)
                                    <td>
                                        <input type="{{ $type }}" name="nominees[{{ $i }}][{{ $field }}]" value="{{ $n[$field] ?? '' }}" @if ($type === 'number') step="0.01" min="0" max="100" @endif
                                            class="form-control form-control-sm @error("nominees.$i.$field") is-invalid @enderror" aria-label="{{ __('Nominee :n :field', ['n' => $i + 1, 'field' => str_replace('_', ' ', $field)]) }}">
                                        @error("nominees.$i.$field") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" class="nx-btn nx-btn-light mt-3" data-add-nominee @if (count($nominees) >= 5) hidden @endif><i class="fas fa-plus" aria-hidden="true"></i> {{ __('Add nominee') }}</button>
            <x-slot:footer>
                <x-nit::button variant="light" :href="$editing ? route('members.show', $member) : route('members.index')">{{ __('Cancel') }}</x-nit::button>
                <x-nit::button icon="fas fa-check">{{ $editing ? __('Save') : __('Register') }}</x-nit::button>
            </x-slot:footer>
        </x-nit::card>
    </form>
</div>
@endsection

@push('js')
<script>
    (function () {
        var button = document.querySelector('[data-add-nominee]');
        var body = document.querySelector('[data-nominees] tbody');
        if (!button || !body) return;
        button.addEventListener('click', function () {
            var index = body.rows.length;
            var row = body.rows[0].cloneNode(true);
            row.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/nominees\[\d+\]/, 'nominees[' + index + ']');
                input.value = '';
                input.classList.remove('is-invalid');
            });
            row.querySelectorAll('.invalid-feedback').forEach(function (el) { el.remove(); });
            body.appendChild(row);
            button.hidden = body.rows.length >= 5;
        });
    })();
</script>
@endpush
