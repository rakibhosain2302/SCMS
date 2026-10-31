@extends('nit::master')
@section('title', __('Import members'))
@section('nx-inline-errors', '1')

@section('content')
<div class="nx-page">
    @if ($report)
        <x-nit::card :title="$report['dry_run'] ? __('Check result (nothing was saved)') : __('Import result')" class="mb-5">
            @if ($report['error'])
                <p class="text-danger mb-0">{{ $report['error'] }}</p>
            @else
                <p class="mb-2">{{ $report['dry_run'] ? __(':valid of :rows rows are ready to import.', ['valid' => $report['valid'], 'rows' => $report['rows']]) : __(':n members registered.', ['n' => $report['created']]) }}</p>
                @if ($report['skipped'])
                    <ul class="mb-0">@foreach ($report['skipped'] as $s)<li>{{ __('Line :line: :reason', ['line' => $s['line'], 'reason' => $s['reason']]) }}</li>@endforeach</ul>
                @endif
            @endif
        </x-nit::card>
    @endif

    <form method="POST" action="{{ route('members.import.store') }}" enctype="multipart/form-data" data-nx-once>
        @csrf
        <x-nit::card :title="__('Import members from a CSV file')" :subtitle="__('First row names the columns: :columns. branch_code is the code of one of your branches; dates as YYYY-MM-DD. member_no may be left empty for the next number. Up to 1,000 rows.', ['columns' => implode(', ', $columns)])">
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <div><label for="member-csv" class="form-label">{{ __('CSV file') }}</label><input id="member-csv" type="file" name="file" accept=".csv,text/csv" class="form-control @error('file') is-invalid @enderror" required>@error('file')<p class="nx-error">{{ $message }}</p>@enderror</div>
                <label class="nx-check"><input type="checkbox" name="dry_run" value="1" checked> <span>{{ __('Check only (save nothing)') }}</span></label>
                <button type="submit" class="nx-btn nx-btn-primary"><i class="fas fa-file-import" aria-hidden="true"></i> {{ __('Upload') }}</button>
            </div>
            <x-slot:footer>
                <x-nit::button variant="light" :href="route('members.index')" icon="fas fa-arrow-left">{{ __('Members') }}</x-nit::button>
            </x-slot:footer>
        </x-nit::card>
    </form>
</div>
@endsection
