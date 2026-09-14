<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Farm\RattingSessionService;
use App\Services\Farm\SystemTourService;
use App\Services\Farm\TargetScorerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Region-based farm advisor: pick a region, score every system in it, and
 * plan an optimal tour over the best N.
 */
class FarmAdvisor extends Component
{
    public Character $character;

    public ?int $regionId = null;

    public string $regionSearch = '';

    public bool $searchOpen = false;

    public string $securityBand = 'any';

    public string $faction = '';

    public int $tourSize = 8;

    public ?string $notice = null;

    #[On('safety-changed')]
    public function onSafetyChanged(): void
    {
        // Re-render with the new routing setting.
    }

    public function updatedRegionSearch(): void
    {
        $this->searchOpen = true;
    }

    public function pickRegion(int $regionId): void
    {
        $this->regionId = $regionId;
        $this->searchOpen = false;
        $this->regionSearch = (string) DB::table('regions')->where('region_id', $regionId)->value('name');
    }

    public function sendTour(array $systemIds, EsiClientInterface $esi): void
    {
        $this->pushWaypoints($systemIds, $esi, 'Tour');
    }

    public function sendFullPath(array $systemIds, EsiClientInterface $esi): void
    {
        // Pushing every hop forces the client onto the exact route we planned.
        $this->pushWaypoints($systemIds, $esi, 'Full path');
    }

    public function setDestination(int $systemId, EsiClientInterface $esi): void
    {
        $this->pushWaypoints([$systemId], $esi, 'Destination');
    }

    private function pushWaypoints(array $systemIds, EsiClientInterface $esi, string $label): void
    {
        try {
            foreach (array_values($systemIds) as $i => $systemId) {
                $esi->post('/ui/autopilot/waypoint', [
                    'destination_id' => (int) $systemId,
                    'add_to_beginning' => 'false',
                    'clear_other_waypoints' => $i === 0 ? 'true' : 'false',
                ], $this->character);
            }
            $this->notice = $label.' ('.count($systemIds).' waypoint'.(count($systemIds) === 1 ? '' : 's').') sent to the EVE client. Good hunting o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set waypoints ('.$e->getMessage().')';
        }
    }

    public function render(
        EsiClientInterface $esi,
        TargetScorerService $scorer,
        SystemTourService $tours,
        RattingSessionService $ratting,
    ): View {
        [$originId, $originName, $originRegionId] = $this->origin($esi);

        // Default to the region the pilot is currently in.
        if ($this->regionId === null && $originRegionId !== null) {
            $this->regionId = $originRegionId;
            $this->regionSearch = (string) DB::table('regions')->where('region_id', $originRegionId)->value('name');
        }

        $this->tourSize = max(3, min(25, $this->tourSize));
        $minSecurity = $this->character->minRouteSecurity();

        $scored = $this->regionId !== null
            ? $scorer->scoreRegion(
                $this->regionId,
                $this->character,
                $minSecurity,
                $this->faction !== '' ? $this->faction : null,
                in_array($this->securityBand, ['highsec', 'lowsec', 'nullsec'], true) ? $this->securityBand : 'any',
                $originId,
            )
            : collect();

        // Feed the tour a wider pool (with scores) than it will pick, so the
        // orienteering can trade a couple of jumps for a high-value dead-end
        // that a strict top-N would have missed.
        $candidates = $scored->take(max(30, $this->tourSize * 3))
            ->mapWithKeys(fn ($s) => [$s->systemId => max(0.1, $s->score)])
            ->all();

        $tour = ($originId !== null && $candidates !== [])
            ? $tours->tour($originId, $candidates, $this->tourSize, $minSecurity)
            : null;

        return view('livewire.farm-advisor', [
            'originName' => $originName,
            'regionName' => $this->regionId !== null ? DB::table('regions')->where('region_id', $this->regionId)->value('name') : null,
            'regionResults' => $this->searchRegions(),
            'scored' => $scored,
            'usingHistory' => $scored->usingHistory ?? false,
            'tour' => $tour,
            'factions' => array_keys(config('eve.factions')),
            'sessions' => $ratting->sessions($this->character)->take(10),
            'dailyTotals' => $ratting->dailyTotals($this->character),
        ]);
    }

    /**
     * Search regions by region name OR a system name within them.
     *
     * @return Collection<int, object>
     */
    private function searchRegions(): Collection
    {
        if (! $this->searchOpen || mb_strlen(trim($this->regionSearch)) < 2) {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->regionSearch)).'%';

        $byRegion = DB::table('regions')
            ->where('name', 'like', $like)
            ->select('region_id', 'name', DB::raw('NULL as via_system'));

        $bySystem = DB::table('solar_systems as s')
            ->join('regions as r', 'r.region_id', '=', 's.region_id')
            ->where('s.name', 'like', $like)
            ->select('r.region_id', 'r.name', 's.name as via_system');

        return $byRegion->union($bySystem)
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->unique('region_id')
            ->values();
    }

    /**
     * @return array{0: ?int, 1: string, 2: ?int} system id, name, region id
     */
    private function origin(EsiClientInterface $esi): array
    {
        try {
            $location = $esi->get("/characters/{$this->character->character_id}/location", [], $this->character);
            $systemId = (int) $location->data['solar_system_id'];

            $row = DB::table('solar_systems')->where('system_id', $systemId)->first(['name', 'region_id']);

            return [$systemId, $row->name ?? "#{$systemId}", $row ? (int) $row->region_id : null];
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [null, 'unknown (ESI unavailable)', null];
        }
    }
}
