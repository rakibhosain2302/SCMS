@extends('nit::master')
@section('title', __('Dashboard'))

@section('content')
<div class="nx-page">
    <x-nit::card :title="__('Welcome, :name', ['name' => auth()->user()->name])">
        {{ __('You are signed in.') }}
    </x-nit::card>
</div>
@endsection
