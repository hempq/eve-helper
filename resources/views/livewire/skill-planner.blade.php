<div>
    <div class="card" style="margin-bottom: 14px">
        <h2>Add a goal skill</h2>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search skills… (e.g. Gallente Cruiser)"
                   style="flex: 1; min-width: 240px; background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 8px 12px; font: inherit;">
            <div style="display: flex; gap: 4px;">
                @foreach ([1, 2, 3, 4, 5] as $l)
                    <button wire:click="$set('level', {{ $l }})"
                            style="width: 36px; padding: 7px 0; border-radius: 6px; cursor: pointer; font: 600 13px var(--font-display);
                                   border: 1px solid {{ $level === $l ? 'var(--accent)' : 'var(--line)' }};
                                   background: {{ $level === $l ? 'var(--accent-dim)' : 'transparent' }};
                                   color: {{ $level === $l ? 'var(--accent)' : 'var(--muted)' }};">
                        {{ ['I', 'II', 'III', 'IV', 'V'][$l - 1] }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($results->isNotEmpty())
            <table style="margin-top: 10px;">
                @foreach ($results as $result)
                    <tr>
                        <td>{{ $result->name }}</td>
                        <td class="muted" style="font-size: 12.5px">
                            r{{ $result->rank }} · {{ substr($result->primary_attribute, 0, 3) }}/{{ substr($result->secondary_attribute, 0, 3) }}
                        </td>
                        <td class="r">
                            <button wire:click="addSkill({{ $result->type_id }})"
                                    style="background: var(--accent); color: var(--bg); border: 0; border-radius: 6px; padding: 4px 14px; cursor: pointer; font: 600 12.5px var(--font-body);">
                                Add {{ ['I', 'II', 'III', 'IV', 'V'][$level - 1] }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </table>
        @elseif (mb_strlen(trim($search)) >= 2)
            <p class="muted" style="margin: 10px 0 0">No matching skill.</p>
        @endif
    </div>

    @if ($targets->isNotEmpty())
        <div class="card" style="margin-bottom: 14px">
            <h2>Goals
                <button wire:click="clearPlan" wire:confirm="Clear the whole plan?"
                        style="float: right; background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 6px; padding: 2px 10px; cursor: pointer; font: 500 12px var(--font-body);">
                    Clear plan
                </button>
            </h2>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                @foreach ($targets as $target)
                    <span class="chip">
                        <b>{{ $targetNames[$target->skill_id] ?? 'Skill #'.$target->skill_id }}</b>
                        {{ ['I', 'II', 'III', 'IV', 'V'][$target->target_level - 1] }}
                        <a href="#" wire:click.prevent="removeTarget({{ $target->id }})" title="Remove" style="color: var(--bad); margin-left: 4px;">✕</a>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @if ($entries->isEmpty())
        <div class="card">
            <p class="muted" style="margin: 0">
                The plan is empty. Search a skill above — prerequisites are added automatically,
                and levels you already trained are skipped.
            </p>
        </div>
    @else
        <div class="tiles">
            <div class="tile">
                <div class="label">Plan size</div>
                <div class="value">{{ $entries->count() }}</div>
                <div class="hint">skill levels to train</div>
            </div>
            <div class="tile">
                <div class="label">Total SP</div>
                <div class="value">{{ number_format($entries->sum('sp')) }}</div>
                @if ($unallocatedSp > 0)
                    <div class="hint gold">{{ number_format(min($unallocatedSp, $entries->sum('sp'))) }} coverable by unallocated SP</div>
                @endif
            </div>
            @if ($report !== null)
                <div class="tile">
                    <div class="label">Time @ current map</div>
                    <div class="value">{{ \App\Support\Duration::minutes($report->currentMinutes) }}</div>
                    @if ($unallocatedSp > 0 && $report->totalSp > 0)
                        <div class="hint">− {{ \App\Support\Duration::minutes($report->currentMinutes * min($unallocatedSp, $report->totalSp) / $report->totalSp) }} if you apply unallocated SP</div>
                    @endif
                </div>
                <div class="tile">
                    <div class="label">With optimal remap</div>
                    <div class="value ok">{{ \App\Support\Duration::minutes($report->optimalMinutes) }}</div>
                    <div class="hint">
                        saves {{ \App\Support\Duration::minutes($report->savedMinutes()) }} ·
                        {{ collect($report->optimalBase->toArray())->map(fn ($v, $k) => strtoupper(substr($k, 0, 3)).' '.$v)->implode(' / ') }}
                    </div>
                </div>
            @endif
        </div>

        <div class="card wide">
            <h2>Training order</h2>
            <div class="tablewrap">
                <table>
                    <tr>
                        <th>#</th><th>Skill</th><th>Level</th><th class="r">SP needed</th>
                        <th class="r">Time @ current</th><th class="r">Cumulative</th>
                    </tr>
                    @php $cumulative = 0; @endphp
                    @foreach ($entries as $i => $entry)
                        @php
                            $rate = $report?->currentAttributes
                                ? $report->currentAttributes->get($entry->primaryAttribute) + $report->currentAttributes->get($entry->secondaryAttribute) / 2
                                : null;
                            $minutes = $rate ? $entry->sp / $rate : null;
                            $cumulative += $minutes ?? 0;
                        @endphp
                        <tr>
                            <td class="num muted">{{ $i + 1 }}</td>
                            <td>
                                {{ $entry->name }}
                                <span class="muted" style="font-size: 12px">· r{{ $entry->rank }}</span>
                                @if ($entry->isPrerequisite)
                                    <span class="chip" title="Added automatically as a prerequisite">prereq</span>
                                @endif
                            </td>
                            <td class="num">{{ ['I', 'II', 'III', 'IV', 'V'][$entry->level - 1] }}</td>
                            <td class="r num">{{ number_format($entry->sp) }}</td>
                            <td class="r num">{{ $minutes !== null ? \App\Support\Duration::minutes($minutes) : '—' }}</td>
                            <td class="r num muted">{{ $minutes !== null ? \App\Support\Duration::minutes($cumulative) : '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif
</div>
