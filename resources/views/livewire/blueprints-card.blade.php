<div class="card wide" style="margin-top: 24px">
    <h2>Blueprints
        @if (! $result->needsScope && $result->blueprints->isNotEmpty())
            <span class="muted" style="text-transform:none; letter-spacing:0">
                — {{ $result->originals }} original{{ $result->originals === 1 ? '' : 's' }} · {{ $result->copies }} cop{{ $result->copies === 1 ? 'y' : 'ies' }}
            </span>
        @endif
    </h2>

    @if ($result->needsScope)
        <p class="muted" style="margin:0">Needs the blueprints scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($result->blueprints->isEmpty())
        <p class="muted" style="margin:0">No blueprints owned.</p>
    @else
        <div class="tablewrap">
            <table class="sortable">
                <tr>
                    <th>Blueprint</th><th>Kind</th>
                    <th class="r" title="Material efficiency — 10 is fully researched">ME</th>
                    <th class="r" title="Time efficiency — 20 is fully researched">TE</th>
                    <th class="r">Count</th><th class="r">Runs</th><th></th>
                </tr>
                @foreach ($result->blueprints->take(40) as $bp)
                    <tr>
                        <td>{{ $bp->name }}</td>
                        <td>{!! $bp->isCopy ? '<span class="chip" style="font-size:10.5px; padding:0 6px">BPC</span>' : '<span class="chip gold" style="font-size:10.5px; padding:0 6px">BPO</span>' !!}</td>
                        <td class="r num {{ $bp->me >= 10 ? 'ok' : '' }}">{{ $bp->me }}</td>
                        <td class="r num {{ $bp->te >= 20 ? 'ok' : '' }}">{{ $bp->te }}</td>
                        <td class="r num">{{ $bp->count }}</td>
                        <td class="r num muted">{{ $bp->isCopy ? $bp->runs : '∞' }}</td>
                        <td>
                            @if (! $bp->isCopy && ($bp->me < 10 || $bp->te < 20))
                                <span class="chip" title="An unresearched original wastes materials/time on every run — research it (or have it researched) before serious production">research me</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        @if ($result->blueprints->count() > 40)
            <p class="muted" style="margin-bottom:0">… and {{ $result->blueprints->count() - 40 }} more.</p>
        @endif
    @endif
</div>
