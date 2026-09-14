@extends('layouts.app')

@section('title', 'Industry — '.$character->name)

@section('content')
    <h1>Industry &amp; colonies</h1>
    <p class="sub">Jobs, blueprints, planetary colonies and the mining ledger in one place.</p>

    <div class="cards">
        <livewire:industry-jobs-card :character="$character" lazy />
        <livewire:planetary-card :character="$character" lazy />
    </div>

    <div class="cards" style="margin-top: 24px">
        <livewire:mining-card :character="$character" lazy />
    </div>

    <livewire:blueprints-card :character="$character" lazy />
@endsection
