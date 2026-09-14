@extends('layouts.app')

@section('title', 'Skill planner — '.$character->name)

@section('content')
    <h1>Skill planner</h1>
    <p class="sub">Pick goal skills — prerequisites resolve automatically, trained levels are skipped, and the plan gets its own remap analysis.</p>

    <livewire:skill-planner :character="$character" />
@endsection
