<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\UndercutService;
use App\Services\Universe\RouteService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates in-app alerts for every character from conditions worth a nudge:
 * a stalling skill queue, undercut market orders, expiring escalations, and
 * clone state (jump clone off cooldown, death clone far away). Each
 * condition has a stable dedupe key so it produces one live alert, and
 * alerts whose condition has cleared are removed.
 */
class AlertService
{
    private const DEATH_CLONE_JUMPS = 10;

    public function __construct(
        private readonly UndercutService $undercut,
        private readonly EsiClientInterface $esi,
        private readonly RouteService $routes,
    ) {}

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
            $this->cloneAlerts($character),
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

    /**
     * Jump clone off cooldown (nudged for 48h so it doesn't nag forever) and
     * a death clone parked far from where the pilot actually is.
     *
     * @return list<array<string,mixed>>
     */
    private function cloneAlerts(Character $character): array
    {
        $alerts = [];

        $hasJumpClones = DB::table('character_clones')
            ->where('character_id', $character->character_id)
            ->exists();

        if ($hasJumpClones && $character->last_clone_jump_date !== null) {
            // Infomorph Synchronizing shaves an hour per level off the 24h
            // cooldown (matched by SDE name — the id is the fragile part).
            $level = (int) DB::table('character_skills as cs')
                ->join('item_types as it', 'it.type_id', '=', 'cs.skill_id')
                ->where('cs.character_id', $character->character_id)
                ->where('it.name', 'Infomorph Synchronizing')
                ->value('cs.active_level');

            $readyAt = CarbonImmutable::parse($character->last_clone_jump_date)->addHours(24 - $level);

            if ($readyAt->isPast() && $readyAt->gt(now()->subHours(48))) {
                $alerts[] = [
                    'type' => 'clone', 'dedupe_key' => 'jump_clone_ready',
                    'message' => 'Jump clone cooldown is over — you can clone jump again.',
                    'url' => null, 'severity' => 'info',
                ];
            }
        }

        $alerts = [...$alerts, ...$this->deathCloneAlert($character)];

        return $alerts;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function deathCloneAlert(Character $character): array
    {
        if ($character->home_location_id === null) {
            return [];
        }

        $homeSystemId = $this->systemOfLocation((int) $character->home_location_id, (string) $character->home_location_type, $character);

        if ($homeSystemId === null) {
            return [];
        }

        try {
            $location = $this->esi->get("/characters/{$character->character_id}/location", [], $character);
            $currentSystemId = (int) $location->data['solar_system_id'];
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [];
        }

        $jumps = $this->routes->jumps($currentSystemId, $homeSystemId, preferSafer: false);

        if ($jumps === null || $jumps <= self::DEATH_CLONE_JUMPS) {
            return [];
        }

        $homeSystem = DB::table('solar_systems')->where('system_id', $homeSystemId)->value('name') ?? "#{$homeSystemId}";

        return [[
            'type' => 'clone', 'dedupe_key' => 'death_clone_far',
            'message' => "Your death clone is {$jumps} jumps away in {$homeSystem} — if podded you respawn there. Consider moving it closer.",
            'url' => null, 'severity' => 'warn',
        ]];
    }

    private function systemOfLocation(int $locationId, string $locationType, Character $character): ?int
    {
        if ($locationType === 'structure') {
            try {
                $structure = $this->esi->get("/universe/structures/{$locationId}", [], $character)->data;

                return isset($structure['solar_system_id']) ? (int) $structure['solar_system_id'] : null;
            } catch (EsiErrorLimited|EsiRequestFailed) {
                return null;
            }
        }

        $systemId = DB::table('stations')->where('station_id', $locationId)->value('system_id');

        return $systemId !== null ? (int) $systemId : null;
    }
}
