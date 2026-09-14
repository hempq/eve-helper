<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Character;
use App\Services\Market\UndercutService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates in-app alerts for every character from conditions worth a nudge:
 * a stalling skill queue, undercut market orders, and expiring escalations.
 * Each condition has a stable dedupe key so it produces one live alert, and
 * alerts whose condition has cleared are removed.
 */
class AlertService
{
    public function __construct(private readonly UndercutService $undercut) {}

    public function refreshAll(): int
    {
        $count = 0;
        foreach (Character::all() as $character) {
            $count += $this->refresh($character);
        }

        return $count;
    }

    public function refresh(Character $character): int
    {
        $live = [];

        $live = array_merge(
            $live,
            $this->skillQueueAlerts($character),
            $this->undercutAlerts($character),
            $this->escalationAlerts($character),
        );

        $liveKeys = array_column($live, 'dedupe_key');

        // Drop alerts whose condition no longer holds.
        Alert::where('character_id', $character->character_id)
            ->when($liveKeys !== [], fn ($q) => $q->whereNotIn('dedupe_key', $liveKeys))
            ->when($liveKeys === [], fn ($q) => $q)
            ->delete();

        foreach ($live as $alert) {
            Alert::updateOrCreate(
                ['character_id' => $character->character_id, 'dedupe_key' => $alert['dedupe_key']],
                $alert + ['character_id' => $character->character_id],
            );
        }

        return count($live);
    }

    /**
     * @return list<array{type:string,dedupe_key:string,message:string,url:?string,severity:string}>
     */
    private function skillQueueAlerts(Character $character): array
    {
        $last = DB::table('character_skill_queue')
            ->where('character_id', $character->character_id)
            ->max('finish_date');

        if ($last === null) {
            return [[
                'type' => 'skill_queue', 'dedupe_key' => 'skill_queue_empty',
                'message' => 'Your skill queue is empty — you are not training.',
                'url' => '/skills', 'severity' => 'urgent',
            ]];
        }

        $ends = CarbonImmutable::parse($last);
        if ($ends->isBetween(now(), now()->addDay())) {
            return [[
                'type' => 'skill_queue', 'dedupe_key' => 'skill_queue_ending',
                'message' => 'Skill queue finishes '.$ends->diffForHumans().' — add more skills.',
                'url' => '/planner', 'severity' => 'warn',
            ]];
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function undercutAlerts(Character $character): array
    {
        $undercut = $this->undercut->check($character)->where('undercut', true);

        if ($undercut->isEmpty()) {
            return [];
        }

        return [[
            'type' => 'undercut', 'dedupe_key' => 'undercut_orders',
            'message' => $undercut->count().' of your market orders are undercut.',
            'url' => '/market', 'severity' => 'warn',
        ]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function escalationAlerts(Character $character): array
    {
        $soon = DB::table('signatures')
            ->where('character_id', $character->character_id)
            ->where('sig_group', 'Escalation')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<', now()->addHours(6))
            ->get(['id', 'name', 'expires_at']);

        return $soon->map(fn ($e) => [
            'type' => 'escalation',
            'dedupe_key' => 'escalation_'.$e->id,
            'message' => 'Escalation "'.($e->name ?? 'unnamed').'" expires '.CarbonImmutable::parse($e->expires_at)->diffForHumans().'.',
            'url' => '/farm',
            'severity' => 'urgent',
        ])->all();
    }
}
