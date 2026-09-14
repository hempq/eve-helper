<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\AppraisalResult;
use App\Services\Market\AppraisalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

class MarketAppraisal extends Component
{
    public Character $character;

    public string $paste = '';

    public int $stationId;

    public ?string $error = null;

    private ?AppraisalResult $result = null;

    public function mount(): void
    {
        $this->stationId = (int) config('eve.market.default_hub');
    }

    public function appraise(AppraisalService $service): void
    {
        $this->error = null;

        if (! array_key_exists($this->stationId, config('eve.market.hubs'))) {
            $this->stationId = (int) config('eve.market.default_hub');
        }

        if (trim($this->paste) === '') {
            $this->result = null;

            return;
        }

        try {
            $this->result = $service->appraise($this->character, $this->stationId, $this->paste);
        } catch (RequestException $e) {
            $this->error = 'Price service is unavailable right now ('.$e->getCode().'). Try again in a minute.';
        }
    }

    public function render(AppraisalService $service): View
    {
        // Re-run the appraisal on interactive updates (hub switch) so the
        // table follows the selected hub; prices are cached client-side of
        // Fuzzwork by our provider, so this stays cheap.
        if ($this->result === null && trim($this->paste) !== '' && $this->error === null) {
            $this->appraise($service);
        }

        return view('livewire.market-appraisal', [
            'result' => $this->result,
            'hubs' => config('eve.market.hubs'),
        ]);
    }
}
