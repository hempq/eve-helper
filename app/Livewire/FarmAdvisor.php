<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Farm\ConstellationTourService;
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

    public ?int $tourConstellationId = null;

    public string $constellationSearch = '';

    public ?string $notice = null;

    public function planTour(int $constellationId): void
    {
        $this->tourConstellationId = $constellationId;
        $this->constellationSearch = '';
    }

    public function sendTour(array $systemIds, EsiClientInterface $esi): void
    {
        try {
            foreach (array_values($systemIds) as $i => $systemId) {
                $esi->post('/ui/autopilot/waypoint', [
                    'destination_id' => (int) $systemId,
                    'add_to_beginning' => 'false',
                    'clear_other_waypoints' => $i === 0 ? 'true' : 'false',
                ], $this->character);
            }
            $this->notice = 'Tour ('.count($systemIds).' waypoints) sent to the EVE client. Good hunting o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set waypoints ('.$e->getMessage().')';
        }
    }

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
        ConstellationTourService $tours,
    ): View {
        [$originId, $originName] = $this->origin($esi);

        $this->maxJumps = max(1, min(25, $this->maxJumps));

        // The global high-sec-only setting constrains the scan traversal —
        // unless the pilot explicitly asks for low/null targets here.
        $avoidUnsafe = $this->character->avoidsLowsec()
            && ! in_array($this->securityBand, ['lowsec', 'nullsec'], true);

        $scored = $originId !== null
            ? $scorer->score(
                $originId,
                $this->maxJumps,
                in_array($this->securityBand, ['highsec', 'lowsec', 'nullsec'], true) ? $this->securityBand : 'any',
                $this->faction !== '' ? $this->faction : null,
                $this->useWormholes ? $eveScout->edges() : [],
                $this->character,
                $avoidUnsafe,
            )
            : collect();

        $constellations = $tours->rank($scored);

        // Default the tour to the best-ranked constellation.
        if ($this->tourConstellationId === null && $constellations->isNotEmpty()) {
            $this->tourConstellationId = $constellations->first()->constellationId;
        }

        $tour = ($originId !== null && $this->tourConstellationId !== null)
            ? $tours->tour($originId, $this->tourConstellationId, $avoidUnsafe)
            : null;

        $searchResults = mb_strlen(trim($this->constellationSearch)) >= 2
            ? DB::table('constellations as c')
                ->join('regions as r', 'r.region_id', '=', 'c.region_id')
                ->where('c.name', 'like', trim($this->constellationSearch).'%')
                ->orderBy('c.name')
                ->limit(8)
                ->get(['c.constellation_id', 'c.name', 'r.name as region'])
            : collect();

        return view('livewire.farm-advisor', [
            'originName' => $originName,
            'targets' => $scored->take(25),
            'constellationResults' => $searchResults,
            'constellations' => $constellations,
            'tour' => $tour,
            'tourName' => $constellations->firstWhere('constellationId', $this->tourConstellationId)?->name
                ?? \Illuminate\Support\Facades\DB::table('constellations')->where('constellation_id', $this->tourConstellationId)->value('name'),
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
