<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\AssetLocationService;
use App\Services\Market\SellTripPlanner;
use App\Services\Universe\KillActivityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class AssetsAdvisor extends Component
{
    #[On('safety-changed')]
    public function onSafetyChanged(): void
    {
        // Re-render with the new routing setting.
    }

    public Character $character;

    public ?int $locationId = null;

    /** @var 'order'|'instant' */
    public string $mode = 'order';

    /** ISK a detour jump must earn (millions, from the UI selector). */
    public int $iskPerJumpM = 2;

    public ?string $notice = null;

    public function sendRoute(array $stationIds, EsiClientInterface $esi): void
    {
        try {
            foreach (array_values($stationIds) as $i => $stationId) {
                $esi->post('/ui/autopilot/waypoint', [
                    'destination_id' => (int) $stationId,
                    'add_to_beginning' => 'false',
                    'clear_other_waypoints' => $i === 0 ? 'true' : 'false',
                ], $this->character);
            }

            $this->notice = count($stationIds) > 1
                ? 'Full trip ('.count($stationIds).' waypoints) sent to the EVE client. Fly safe o7'
                : 'Destination set in the EVE client. Fly safe o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set waypoints — is the client running and the character online? ('.$e->getMessage().')';
        }
    }

    public function render(
        AssetLocationService $locationService,
        SellTripPlanner $planner,
        KillActivityService $killActivity,
    ): View {
        $locations = $locationService->locations($this->character);

        $selected = $this->locationId !== null
            ? $locations->firstWhere('location_id', $this->locationId)
            : null;

        $plan = null;
        $legs = null;

        if ($selected !== null && $selected->typeQuantities !== []) {
            if (! in_array($this->mode, ['order', 'instant'], true)) {
                $this->mode = 'order';
            }
            $this->iskPerJumpM = max(0, min(100, $this->iskPerJumpM));

            try {
                $plan = $planner->plan(
                    $this->character,
                    $selected->typeQuantities,
                    $this->originSystemId($selected),
                    $this->mode,
                    $this->iskPerJumpM * 1_000_000,
                );

                $legs = $plan !== null ? $this->describeLegs($plan, $killActivity) : null;
            } catch (RequestException) {
                $this->notice = 'Price service is unavailable right now. Try again in a minute.';
            }
        }

        return view('livewire.assets-advisor', [
            'locations' => $locations,
            'selected' => $selected,
            'plan' => $plan,
            'legs' => $legs,
        ]);
    }

    private function originSystemId(object $selected): ?int
    {
        $id = match ($selected->location_type) {
            'station' => DB::table('stations')->where('station_id', $selected->location_id)->value('system_id'),
            'solar_system' => $selected->location_id,
            default => null,
        };

        return $id !== null ? (int) $id : null;
    }

    /**
     * Per-stop route details: security-colored system chips + kill warnings.
     *
     * @return array<int, list<object>>  keyed by stop index
     */
    private function describeLegs(object $plan, KillActivityService $killActivity): array
    {
        $systemIds = collect($plan->stops)->flatMap(fn ($stop) => $stop->route ?? [])->unique();

        if ($systemIds->isEmpty()) {
            return [];
        }

        $kills = $killActivity->playerKills();
        $systems = DB::table('solar_systems')
            ->whereIn('system_id', $systemIds)
            ->get(['system_id', 'name', 'security'])
            ->keyBy('system_id');

        $legs = [];

        foreach ($plan->stops as $index => $stop) {
            if ($stop->route === null) {
                continue;
            }

            $legs[$index] = array_map(fn (int $id) => (object) [
                'name' => $systems[$id]->name ?? "#{$id}",
                'security' => round((float) ($systems[$id]->security ?? 0), 1),
                'kills' => $kills[$id] ?? 0,
                'dangerous' => $killActivity->isDangerous($kills[$id] ?? 0),
            ], $stop->route);
        }

        return $legs;
    }
}
