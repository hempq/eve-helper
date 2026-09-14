@extends('layouts.app')

@section('title', 'Skills — '.$character->name)

@section('content')
    <h1>Skills</h1>
    <p class="sub">{{ $totalSkills }} skills · {{ number_format($character->total_sp ?? 0) }} SP
        @if (($character->unallocated_sp ?? 0) > 0)
            · <span class="gold">{{ number_format($character->unallocated_sp) }} SP unallocated</span>
        @endif
    </p>

    <livewire:skill-economy :character="$character" />

    @foreach ($groups as $group)
        <div class="card" style="margin-bottom: 14px">
            <h2>{{ $group->name }}
                <span class="muted" style="text-transform:none; letter-spacing:0">
                    — {{ $group->skills->count() }} skills · {{ number_format($group->sp) }} SP · {{ $group->atFive }}× level V
                </span>
            </h2>
            <div class="tablewrap">
                <table class="sortable">
                    <tr>
                        <th>Skill</th><th>Level</th><th class="r">Skillpoints</th>
                        <th>Attributes</th><th class="r">Rank</th>
                    </tr>
                    @foreach ($group->skills as $skill)
                        <tr>
                            <td>{{ $skill->name }}
                                @if ($skill->active_level < $skill->trained_level)
                                    <span class="chip down" title="Limited by Alpha clone state">α {{ $skill->active_level }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="pips">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <span @class(['pip', 'full' => $i <= $skill->trained_level])></span>
                                    @endfor
                                </span>
                                <span class="num muted" style="margin-left:6px">{{ $skill->trained_level }}</span>
                            </td>
                            <td class="r num">{{ number_format($skill->skillpoints) }}</td>
                            <td class="muted" style="font-size:12.5px">
                                {{ $skill->primary_attribute ? substr($skill->primary_attribute, 0, 3).' / '.substr($skill->secondary_attribute, 0, 3) : '—' }}
                            </td>
                            <td class="r num">{{ $skill->rank ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endforeach
@endsection
