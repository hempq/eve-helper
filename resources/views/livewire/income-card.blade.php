<div class="card">
    <h2>Income &amp; spending
        <span class="muted" style="text-transform:none; letter-spacing:0">— last
            <select wire:model.live="days" style="background: var(--surface2); border: 1px solid var(--line); border-radius: 6px; color: var(--ink); padding: 2px 6px; font: inherit; font-size: 13px;">
                <option value="7">7</option><option value="30">30</option><option value="90">90</option>
            </select> days
        </span>
    </h2>

    @if ($breakdown->income->isEmpty() && $breakdown->spending->isEmpty())
        <p class="muted" style="margin:0">No wallet activity in this window.</p>
    @else
        <div style="display:flex; gap:16px; margin-bottom: 8px">
            <span class="chip up">in {{ number_format($breakdown->totalIncome / 1_000_000, 1) }}M</span>
            <span class="chip down">out {{ number_format($breakdown->totalSpending / 1_000_000, 1) }}M</span>
            <span class="chip {{ $breakdown->net >= 0 ? 'up' : 'down' }}"><b>net {{ number_format($breakdown->net / 1_000_000, 1) }}M</b></span>
        </div>
        <table>
            @foreach ($breakdown->income->take(6) as $row)
                <tr>
                    <td>{{ $row->bucket }}</td>
                    <td class="r num ok">+{{ number_format($row->amount) }}</td>
                </tr>
            @endforeach
            @foreach ($breakdown->spending->take(5) as $row)
                <tr>
                    <td class="muted">{{ $row->bucket }}</td>
                    <td class="r num bad">−{{ number_format($row->amount) }}</td>
                </tr>
            @endforeach
        </table>
        <p class="muted" style="font-size:12.5px; margin-bottom:0">From the wallet journal (ESI lags ~1h).</p>
    @endif
</div>
