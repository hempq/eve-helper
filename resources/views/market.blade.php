@extends('layouts.app')

@section('title', 'Market — '.$character->name)

@section('content')
    <h1>Market advisor</h1>
    <p class="sub">Loot appraisal with your real tax rates — 5%-percentile hub prices via Fuzzwork.</p>

    <livewire:market-appraisal :character="$character" />
@endsection
