<div class="card">
    <h2>Industry jobs
        @if (! $result->needsScope && $result->readyCount > 0)
            <span class="chip gold">{{ $result->readyCount }} ready</span>
        @endif
    </h2>

    @if ($result->needsScope)
        <p class="muted" style="margin:0">Needs the industry-jobs scope — <a href="{{ route('eve.login') }}">re-log in</a> to grant it.</p>
    @elseif ($result->jobs->isEmpty())
        <p class="muted" style="margin:0">No active industry jobs.</p>
    @else
        <table class="sortable">
            <tr><th>Activity</th><th>Product</th><th class="r">Runs</th><th class="r">Done</th></tr>
            @foreach ($result->jobs->take(8) as $job)
                <tr>
                    <td class="muted">{{ $job->activity }}</td>
                    <td>{{ $job->product }}</td>
                    <td class="r num">{{ $job->runs }}</td>
                    <td class="r num">
                        @if ($job->ready)
                            <span class="ok">deliver!</span>
                        @else
                            {{ $job->endsAt->diffForHumans(short: true) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
