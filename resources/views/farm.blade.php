@extends('layouts.app')

@section('title', 'Farm — '.$character->name)

@section('content')
    <h1>Farm advisor</h1>
    <p class="sub">Target scouting from live activity data, your ratting income, and Thera/Turnur shortcuts.</p>

    <livewire:farm-advisor :character="$character" />

    <livewire:signature-journal :character="$character" />
@endsection
