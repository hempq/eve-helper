<div class="alert-bell" @if($open) style="position: relative" @endif>
    <button wire:click="toggle" class="bell-btn" title="Alerts">
        🔔
        @if ($unread > 0)<span class="bell-badge">{{ $unread }}</span>@endif
    </button>

    @if ($open)
        <div class="bell-panel">
            <div class="bell-head">
                <span>Alerts</span>
                @if ($unread > 0)<button wire:click="markAllRead" class="bell-clear">mark all read</button>@endif
            </div>
            @forelse ($alerts as $alert)
                <a href="{{ $alert->url ?? '#' }}" wire:click="markRead({{ $alert->id }})"
                   class="bell-item @if($alert->read_at) read @endif">
                    <span class="bell-dot bell-{{ $alert->severity }}"></span>
                    <span>{{ $alert->message }}</span>
                </a>
            @empty
                <div class="bell-empty">Nothing needs your attention. o7</div>
            @endforelse
        </div>
    @endif
</div>
