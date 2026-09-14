<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Farm\RattingSessionService;
use App\Services\Farm\TargetScorerService;
use App\Services\Universe\EveScoutService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class FarmAdvisor extends Component
{
    public Character $character;

    public int $maxJumps = 10;

    public string $securityBand = 'any';

    public string $faction = '';

    public bool $useWormholes = false;

    public ?string $notice = null;

    public function setDestination(int $systemId, EsiClientInterface $esi): void
    {
        try {
            $esi->post('/ui/autopilot/waypoint', [
                'destination_id' => $systemId,
                'add_to_beginning' => 'false',
                'clear_other_waypoints' => 'true',
            ], $this->character);

            $this->notice = 'Destination set. Good hunting o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set destination ('.$e->getMessage().')';
        }
    }

    public function render(
        EsiClientInterface $esi,
        TargetScorerService $scorer,
        RattingSessionService $ratting,
        EveScoutService $eveScout,
    ): View {
        [$originId, $originName] = $this->origin($esi);

        $this->maxJumps = max(1, min(25, $this->maxJumps));

        $targets = $originId !== null
            ? $scorer->score(
                $originId,
                $this->maxJumps,
                in_array($this->securityBand, ['highsec', 'lowsec', 'nullsec'], true) ? $this->securityBand : 'any',
                $this->faction !== '' ? $this->faction : null,
                $this->useWormholes ? $eveScout->edges() : [],
            )->take(25)
            : collect();

        return view('livewire.farm-advisor', [
            'originName' => $originName,
            'targets' => $targets,
            'sessions' => $ratting->sessions($this->character)->take(15),
            'dailyTotals' => $ratting->dailyTotals($this->character),
            'factions' => array_keys(config('eve.factions')),
            'shortcuts' => collect($eveScout->connections())->sortBy('remainingHours')->take(12),
        ]);
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function origin(EsiClientInterface $esi): array
    {
        try {
            $location = $esi->get("/characters/{$this->character->character_id}/location", [], $this->character);
            $systemId = (int) $location->data['solar_system_id'];

            $name = DB::table('solar_systems')->where('system_id', $systemId)->value('name') ?? "#{$systemId}";

            return [$systemId, $name];
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [null, 'unknown (ESI unavailable)'];
        }
    }
}
