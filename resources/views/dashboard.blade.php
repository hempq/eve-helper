@extends('layouts.app')

@section('title', 'EVE Helper — '.$character->name)

@section('content')
    <h1>{{ $character->name }}</h1>
    <p class="sub">Character overview — synced from ESI.</p>

    @if ($syncError)
        <div class="flash">Sync problem: {{ $syncError }}</div>
    @endif

    <div class="tiles">
        <div class="tile">
            <div class="label">Total skillpoints</div>
            <div class="value">{{ number_format($character->total_sp ?? 0) }}</div>
            @if (($character->unallocated_sp ?? 0) > 0)
                <div class="hint gold">+ {{ number_format($character->unallocated_sp) }} unallocated</div>
            @endif
        </div>
        <div class="tile">
            <div class="label">Skills known</div>
            <div class="value">{{ $skillCount }}</div>
            <div class="hint">{{ $skillsAtFive }} at level V</div>
        </div>
        <div class="tile">
            <div class="label">Skill queue</div>
            <div class="value">{{ $queue->count() }}</div>
            <div class="hint">
                @if ($queuePaused)
                    <span class="gold">paused</span>
                @elseif ($queueEndsAt)
                    ends {{ \Carbon\CarbonImmutable::parse($queueEndsAt)->diffForHumans() }}
                @else
                    empty
                @endif
            </div>
        </div>
        <div class="tile">
            <div class="label">Remaps available</div>
            <div class="value">{{ $character->bonus_remaps ?? '—' }}</div>
            <div class="hint">
                @if ($character->last_remap_date)
                    last remap {{ $character->last_remap_date->toDateString() }}
                @else
                    never remapped · <a href="{{ route('remap') }}">optimize</a>
                @endif
            </div>
        </div>
    </div>

    <div class="cards">
        <livewire:wealth-card :character="$character" lazy />
        <livewire:income-card :character="$character" lazy />
    </div>

    <livewire:earnings-card :character="$character" lazy />

    @if (\App\Models\Character::count() > 1)
        <livewire:account-overview-card lazy />
    @endif

    <div class="cards" style="margin-top: 24px">
        <livewire:planetary-card :character="$character" lazy />
        <livewire:industry-jobs-card :character="$character" lazy />
    </div>

    <div class="cards" style="margin-top: 24px">
        <livewire:training-status :character="$character" />

        <div class="card">
            <h2>Attributes</h2>
            <table>
                <tr><th>Attribute</th><th class="r">Base</th><th class="r">Implant</th><th class="r">Effective</th></tr>
                @foreach (['perception', 'willpower', 'intelligence', 'memory', 'charisma'] as $attr)
                    <tr>
                        <td style="text-transform: capitalize">{{ $attr }}</td>
                        <td class="r num">{{ ($character->{$attr} ?? 0) - $implantBonuses->get($attr) }}</td>
                        <td class="r num">{{ $implantBonuses->get($attr) > 0 ? '+'.$implantBonuses->get($attr) : '—' }}</td>
                        <td class="r num"><b>{{ $character->{$attr} }}</b></td>
                    </tr>
                @endforeach
            </table>
            @if ($implants->isNotEmpty())
                <p class="muted" style="margin-bottom:0; font-size:12.5px">
                    Implants: {{ $implants->implode(' · ') }}
                </p>
            @endif
        </div>
    </div>

    <div class="card wide">
        <h2>Skill queue</h2>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>#</th><th>Skill</th><th>To level</th>
                    <th class="r">Level SP</th><th class="r">Duration</th><th>Finishes</th>
                </tr>
                @foreach ($queue->take(20) as $entry)
                    <tr>
                        <td class="num muted">{{ $entry->position + 1 }}</td>
                        <td>{{ $entry->name ?? 'Skill #'.$entry->skill_id }}
                            @if ($entry->rank !== null)
                                <span class="muted" style="font-size:12px">· r{{ $entry->rank }}</span>
                            @endif
                        </td>
                        <td class="num">{{ ['I', 'II', 'III', 'IV', 'V'][$entry->finished_level - 1] ?? $entry->finished_level }}</td>
                        <td class="r num">{{ $entry->level_end_sp !== null && $entry->level_start_sp !== null ? number_format($entry->level_end_sp - $entry->level_start_sp) : '—' }}</td>
                        <td class="r num">
                            @if ($entry->start_date && $entry->finish_date)
                                {{ \App\Support\Duration::minutes(\Carbon\CarbonImmutable::parse($entry->start_date)->diffInMinutes(\Carbon\CarbonImmutable::parse($entry->finish_date), true)) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="num muted">{{ $entry->finish_date ? \Carbon\CarbonImmutable::parse($entry->finish_date)->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        @if ($queue->count() > 20)
            <p class="muted" style="margin-bottom:0">… and {{ $queue->count() - 20 }} more entries.</p>
        @endif
    </div>

    <livewire:losses-card :character="$character" lazy />
@endsection
