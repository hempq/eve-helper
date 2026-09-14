<div class="card wide" style="margin-top: 24px">
    <h2>Account overview
        <span class="muted" style="text-transform:none; letter-spacing:0">
            — {{ $rows->count() }} characters · {{ number_format($totalWorth / 1_000_000, 1) }}M ISK total
            · 30d net {{ number_format($totalNet30 / 1_000_000, 1) }}M
        </span>
    </h2>

    <div class="tablewrap">
        <table class="sortable">
            <tr>
                <th>Character</th>
                <th class="r" title="Latest daily net-worth snapshot (wallet only until one exists)">Net worth</th>
                <th class="r">Wallet</th>
                <th class="r" title="Wallet-journal net over the last 30 days">30d net income</th>
                <th class="r">Last sync</th>
            </tr>
            @foreach ($rows as $row)
                <tr>
                    <td><b>{{ $row->name }}</b></td>
                    <td class="r num">{{ $row->worth !== null ? number_format($row->worth) : '—' }}</td>
                    <td class="r num muted">{{ number_format($row->wallet) }}</td>
                    <td class="r num {{ $row->net30 >= 0 ? 'ok' : 'bad' }}">{{ number_format($row->net30) }}</td>
                    <td class="r num muted">{{ $row->lastSync?->diffForHumans(short: true) ?? 'never' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
