<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\AppraisalService;
use App\Services\Market\AssetLocationService;
use App\Services\Market\HubComparisonService;
use App\Services\Universe\KillActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class AssetsAdvisor extends Component
{
    public Character $character;

    public ?int $locationId = null;

    public ?string $notice = null;

    public function setDestination(int $stationId, EsiClientInterface $esi): void
    {
        try {
            $esi->post('/ui/autopilot/waypoint', [
                'destination_id' => $stationId,
                'add_to_beginning' => 'false',
                'clear_other_waypoints' => 'true',
            ], $this->character);

            $this->notice = 'Destination set in the EVE client. Fly safe o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set destination — is the client running and the character online? ('.$e->getMessage().')';
        }
    }

    public function render(
        AssetLocationService $locationService,
        HubComparisonService $comparison,
        AppraisalService $appraisal,
        KillActivityService $killActivity,
    ): View {
        $locations = $locationService->locations($this->character);

        $selected = $this->locationId !== null
            ? $locations->firstWhere('location_id', $this->locationId)
            : null;

        $analysis = null;

        if ($selected !== null && $selected->typeQuantities !== []) {
            try {
                $analysis = $this->analyze($selected, $comparison, $appraisal, $killActivity);
            } catch (RequestException) {
                $this->notice = 'Price service is unavailable right now. Try again in a minute.';
            }
        }

        return view('livewire.assets-advisor', [
            'locations' => $locations,
            'selected' => $selected,
            'analysis' => $analysis,
        ]);
    }

    private function analyze(
        object $selected,
        HubComparisonService $comparison,
        AppraisalService $appraisal,
        KillActivityService $killActivity,
    ): array {
        $originSystemId = $selected->location_type === 'station'
            ? DB::table('stations')->where('station_id', $selected->location_id)->value('system_id')
            : ($selected->location_type === 'solar_system' ? $selected->location_id : null);

        $hubs = $comparison->compare($this->character, $selected->typeQuantities, $originSystemId !== null ? (int) $originSystemId : null);

        $routeSystems = null;

        if ($hubs['best']?->route !== null) {
            $kills = $killActivity->playerKills();

            $systems = DB::table('solar_systems')
                ->whereIn('system_id', $hubs['best']->route)
                ->get(['system_id', 'name', 'security'])
                ->keyBy('system_id');

            $routeSystems = array_map(fn (int $id) => (object) [
                'name' => $systems[$id]->name ?? "#{$id}",
                'security' => round((float) ($systems[$id]->security ?? 0), 1),
                'kills' => $kills[$id] ?? 0,
                'dangerous' => $killActivity->isDangerous($kills[$id] ?? 0),
            ], $hubs['best']->route);
        }

        return [
            'hubs' => $hubs['options'],
            'best' => $hubs['best'],
            'items' => $hubs['best'] !== null
                ? $appraisal->appraiseQuantities($this->character, $hubs['best']->stationId, $selected->typeQuantities)
                : null,
            'routeSystems' => $routeSystems,
        ];
    }
}
