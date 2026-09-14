@extends('layouts.app')

@section('title', 'Market — '.$character->name)

@section('content')
    <h1>Market advisor</h1>
    <p class="sub">Your real assets, your real tax rates — hub comparison with safer-route jumps and gank warnings.</p>

    <livewire:assets-advisor :character="$character" />

    <livewire:orders-monitor :character="$character" />

    <livewire:trade-profit-card :character="$character" lazy />

    <livewire:contracts-card :character="$character" />

    <livewire:lp-store :character="$character" />

    <div style="margin-top: 24px">
        <livewire:market-appraisal :character="$character" />
    </div>
@endsection
