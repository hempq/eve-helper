<div class="card wide" wire:poll.120s style="margin-top: 24px">
    <h2>My market orders
        @if ($rows->isNotEmpty())
            <span class="muted" style="text-transform:none; letter-spacing:0">
                — {{ $rows->count() }} open,
                @if ($undercutCount > 0)
                    <span class="bad">{{ $undercutCount }} undercut</span>
                @else
                    <span class="ok">all competitive</span>
                @endif
            </span>
        @endif
    </h2>

    @if ($rows->isEmpty())
        <p class="muted" style="margin: 0">No open market orders.</p>
    @else
        <div class="tablewrap">
            <table class="sortable">
                <tr>
                    <th>Item</th><th>Type</th><th>Station</th>
                    <th class="r">My price</th><th class="r">Best price</th>
                    <th class="r">Remaining</th><th>Status</th><th>Advice</th>
                </tr>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td>{{ $row->isBuy ? 'buy' : 'sell' }}</td>
                        <td class="muted" style="font-size: 12.5px">{{ \Illuminate\Support\Str::limit($row->locationName, 34) }}</td>
                        <td class="r num">{{ number_format($row->price, 2) }}</td>
                        <td class="r num">{{ $row->bestPrice !== null ? number_format($row->bestPrice, 2) : '—' }}</td>
                        <td class="r num">{{ number_format($row->volumeRemain) }}/{{ number_format($row->volumeTotal) }}</td>
                        <td>
                            @if ($row->unknown)
                                <span class="chip" title="{{ $row->isStructure ? 'No docking access, or no competing orders' : 'No competing orders here' }}">no competition</span>
                            @elseif ($row->undercut)
                                <span class="chip down">undercut</span>
                            @else
                                <span class="chip up">best</span>
                            @endif
                            @if ($row->isStructure)<span class="muted" style="font-size: 10.5px">citadel</span>@endif
                        </td>
                        <td class="muted" style="font-size: 12.5px">
                            @if ($row->undercut)
                                @if ($row->worthUpdating)
                                    match {{ number_format($row->bestPrice, 2) }} (relist ≈ {{ number_format($row->relistCost) }} ISK)
                                @else
                                    sit tight — relist fee eats the gain
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>
