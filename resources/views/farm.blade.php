@extends('layouts.app')

@section('title', 'Farm — '.$character->name)

@section('content')
    <h1>Farm advisor</h1>
    <p class="sub">Pick a region — or a pirate faction's entire space — every system gets scored, and the best become an optimal tour.</p>

    <livewire:farm-advisor :character="$character" />

    <livewire:signature-journal :character="$character" />
@endsection
