<div class="cards" style="margin-bottom: 16px">
    <div class="card">
        <h2>Injectors &amp; extractors <span class="muted" style="text-transform:none; letter-spacing:0">(live Jita prices)</span></h2>
        @if ($analysis === null)
            <p class="muted" style="margin:0">Price data unavailable right now.</p>
        @else
            <table>
                <tr>
                    <td>Large injector yield at your SP</td>
                    <td class="r num"><b>{{ number_format($analysis['yield']) }}</b> SP</td>
                </tr>
                <tr>
                    <td>Injector cost</td>
                    <td class="r num">{{ number_format($analysis['injectorPrice']) }} ISK</td>
                </tr>
                <tr>
                    <td>Cost per injected SP</td>
                    <td class="r num gold">{{ number_format($analysis['iskPerSp'], 2) }} ISK/SP
                        @if ($analysis['useSmall'])<span class="chip" style="font-size:10.5px">smalls cheaper</span>@endif
                    </td>
                </tr>
                <tr>
                    <td>One injector = training time</td>
                    <td class="r num">{{ \App\Support\Duration::minutes($analysis['daysSaved'] * 1440) }}
                        <span class="muted">@ {{ number_format($spPerHour) }} SP/h</span>
                    </td>
                </tr>
            </table>
            <p class="muted" style="font-size: 12.5px; margin: 8px 0 0">
                Buying an injector is worth it when {{ number_format($analysis['iskPerSp'], 2) }} ISK/SP is cheap
                relative to your time — at your rate, one injector skips
                <b>{{ \App\Support\Duration::minutes($analysis['daysSaved'] * 1440) }}</b> of training.
            </p>

            <h2 style="margin-top: 16px">Skill farming</h2>
            <table>
                <tr>
                    <td>Extract 500k SP → sell as injector, net</td>
                    <td class="r num {{ $analysis['farmProfitPerCycle'] > 0 ? 'ok' : 'bad' }}">
                        {{ number_format($analysis['farmProfitPerCycle']) }} ISK
                    </td>
                </tr>
            </table>
            <p class="muted" style="font-size: 12.5px; margin: 8px 0 0">
                Per 500k SP extracted (needs ≥5.5M total SP; extractor {{ number_format($analysis['extractorPrice']) }} ISK,
                injector sells to buy orders at {{ number_format($analysis['injectorSell'] ?? 0) }} ISK, your sales tax applied).
            </p>
        @endif
    </div>

    <div class="card">
        <h2>Jump clones</h2>
        @if ($clones->isEmpty())
            <p class="muted" style="margin:0">No jump clones. Train Infomorph Psychology to install some.</p>
        @else
            <p class="muted" style="margin: 0 0 10px; font-size: 13px">
                Cooldown {{ $cooldownHours }}h ·
                @if ($nextJump === null)
                    <span class="ok">ready to jump</span>
                @elseif ($nextJump->isPast())
                    <span class="ok">ready to jump</span>
                @else
                    next jump {{ $nextJump->diffForHumans() }}
                @endif
            </p>
            @foreach ($clones as $clone)
                <div style="border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin-bottom: 8px;">
                    <div style="font-weight: 600">{{ $clone->name }}</div>
                    <div class="muted" style="font-size: 12.5px">{{ $clone->location }}</div>
                    <div style="font-size: 12.5px; margin-top: 4px">
                        @if ($clone->implants === [])
                            <span class="muted">empty</span>
                        @else
                            {{ implode(' · ', $clone->implants) }}
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</div>
