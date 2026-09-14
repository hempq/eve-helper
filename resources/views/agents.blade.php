@extends('layouts.app')

@section('title', 'Agents — '.$character->name)

@section('content')
    <h1>Mission agents</h1>
    <p class="sub">Which agents near you will actually talk to you — standings-checked, Connections included.</p>

    <livewire:agent-finder :character="$character" />

    <livewire:research-card :character="$character" lazy />
@endsection
