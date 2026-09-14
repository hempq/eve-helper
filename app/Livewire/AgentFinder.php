<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Agents\AgentFinderService;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class AgentFinder extends Component
{
    public Character $character;

    public int $minLevel = 4;

    public string $divisionId = '';

    #[On('safety-changed')]
    public function onSafetyChanged(): void
    {
        // Re-render with the new routing setting.
    }

    public function render(AgentFinderService $finder, EsiClientInterface $esi): View
    {
        $this->minLevel = max(1, min(5, $this->minLevel));

        [$originId, $originName] = $this->origin($esi);

        $result = $originId !== null
            ? $finder->nearby(
                $this->character,
                $originId,
                $this->minLevel,
                $this->divisionId !== '' ? (int) $this->divisionId : null,
            )
            : (object) ['agents' => collect(), 'hasStandings' => false];

        return view('livewire.agent-finder', [
            'agents' => $result->agents,
            'hasStandings' => $result->hasStandings,
            'divisions' => $finder->divisions(),
            'originName' => $originName,
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

            return [$systemId, (string) (DB::table('solar_systems')->where('system_id', $systemId)->value('name') ?? "#{$systemId}")];
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [null, 'unknown'];
        }
    }
}
