<div class="card wide" style="margin-top: 24px">
    <h2>My contracts
        @if ($contracts->isNotEmpty())
            <span class="muted" style="text-transform:none; letter-spacing:0">
                — {{ $contracts->count() }} open · {{ number_format($outstandingValue) }} ISK asking
            </span>
        @endif
    </h2>

    @if ($contracts->isEmpty())
        <p class="muted" style="margin: 0">No open contracts. (Needs the contracts scope — re-log in if this stays empty.)</p>
    @else
        <div class="tablewrap">
            <table>
                <tr><th>Title</th><th>Type</th><th>Status</th><th class="r">Price</th><th class="r">Reward</th><th class="r">Expires</th></tr>
                @foreach ($contracts as $c)
                    <tr>
                        <td>{{ $c->title ?: '—' }}</td>
                        <td class="muted">{{ str_replace('_', ' ', $c->type) }}</td>
                        <td>
                            @if ($c->status === 'in_progress')
                                <span class="chip up">in progress</span>
                            @else
                                <span class="chip">outstanding</span>
                            @endif
                        </td>
                        <td class="r num">{{ $c->price > 0 ? number_format($c->price) : '—' }}</td>
                        <td class="r num">{{ $c->reward > 0 ? number_format($c->reward) : '—' }}</td>
                        <td class="r num muted">
                            @if ($c->date_expired)
                                @php $exp = \Carbon\CarbonImmutable::parse($c->date_expired); @endphp
                                <span class="{{ $exp->diffInDays(now()) < 2 ? 'bad' : '' }}">{{ $exp->diffForHumans() }}</span>
                            @else — @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>
