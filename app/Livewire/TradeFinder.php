<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Market\TradeFinderService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class TradeFinder extends Component
{
    #[On('safety-changed')]
    public function onSafetyChanged(): void
    {
        // Re-render with the new routing setting.
    }

    public Character $character;

    public int $cargo = 5000;

    public int $budgetM = 500;

    public int $fromStation = 0;

    public int $toStation = 0;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->budgetM = (int) min(2000, max(10, floor(($this->character->wallet_balance ?? 500_000_000) / 1_000_000)));
    }

    public function setRoute(int $fromStationId, int $toStationId, EsiClientInterface $esi): void
    {
        try {
            foreach ([[$fromStationId, 'true'], [$toStationId, 'false']] as [$stationId, $clear]) {
                $esi->post('/ui/autopilot/waypoint', [
                    'destination_id' => $stationId,
                    'add_to_beginning' => 'false',
                    'clear_other_waypoints' => $clear,
                ], $this->character);
            }
            $this->notice = 'Trade route sent to the EVE client o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set waypoints ('.$e->getMessage().')';
        }
    }

    public function render(TradeFinderService $finder): View
    {
        $this->cargo = max(100, min(400_000, $this->cargo));
        $this->budgetM = max(1, min(1_000_000, $this->budgetM));

        $hubs = config('eve.market.hubs');

        $result = $finder->find(
            $this->character,
            cargoM3: (float) $this->cargo,
            budget: $this->budgetM * 1_000_000.0,
            fromStationId: array_key_exists($this->fromStation, $hubs) ? $this->fromStation : null,
            toStationId: array_key_exists($this->toStation, $hubs) ? $this->toStation : null,
        );

        return view('livewire.trade-finder', [
            'trades' => $result['trades'],
            'scannedAt' => $result['scannedAt'],
            'hubs' => $hubs,
        ]);
    }
}
