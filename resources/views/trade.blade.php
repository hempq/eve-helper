@extends('layouts.app')

@section('title', 'Trade — '.$character->name)

@section('content')
    <h1>Trade routes</h1>
    <p class="sub">Hub-to-hub hauling flips from full order-book scans, sized to your wallet and cargo.</p>

    <livewire:trade-finder :character="$character" />

    <livewire:station-trading :character="$character" lazy />
@endsection
