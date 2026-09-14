<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Market\TradeFeeService;
use App\Services\Skills\SkillEconomyService;
use App\Services\Skills\TrainingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SkillEconomy extends Component
{
    public Character $character;

    public function render(
        SkillEconomyService $economy,
        TradeFeeService $fees,
        TrainingCalculator $calculator,
    ): View {
        // A representative training rate: the character's two best attributes.
        $attrs = collect(['perception', 'willpower', 'intelligence', 'memory', 'charisma'])
            ->map(fn ($a) => (int) $this->character->{$a})
            ->sortDesc()
            ->values();
        $spPerHour = $calculator->spPerMinute($attrs[0] ?? 20, $attrs[1] ?? 20) * 60;

        $analysis = null;
        try {
            $analysis = $economy->analyze($this->character, $fees->salesTaxRate($this->character), $spPerHour);
        } catch (RequestException) {
            // prices unavailable; card degrades to jump clones only
        }

        $clones = DB::table('character_clones as c')
            ->where('c.character_id', $this->character->character_id)
            ->get()
            ->map(function ($clone) {
                $implantIds = json_decode($clone->implants, true) ?: [];
                $names = $implantIds === [] ? collect() : DB::table('item_types')
                    ->whereIn('type_id', $implantIds)->pluck('name');
                $station = DB::table('stations')->where('station_id', $clone->location_id)->value('name');

                return (object) [
                    'name' => $clone->name ?: 'Jump clone',
                    'location' => $station ?? ('Structure #'.$clone->location_id),
                    'implants' => $names->all(),
                ];
            });

        // Jump cooldown: 24h base, −1h per Advanced Infomorph Psychology level.
        $advInfomorph = (int) DB::table('character_skills')
            ->where('character_id', $this->character->character_id)
            ->where('skill_id', 33399)->value('trained_level');
        $cooldownHours = max(1, 24 - $advInfomorph);
        $nextJump = $this->character->last_clone_jump_date?->addHours($cooldownHours);

        return view('livewire.skill-economy', [
            'analysis' => $analysis,
            'spPerHour' => $spPerHour,
            'clones' => $clones,
            'nextJump' => $nextJump,
            'cooldownHours' => $cooldownHours,
        ]);
    }
}
