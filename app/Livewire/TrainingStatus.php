<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Characters\CharacterSyncService;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Skills\TrainingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Live "currently training" card: polls every 30s, re-syncing through the
 * staleness-guarded sync service so ESI is never hammered.
 */
class TrainingStatus extends Component
{
    public Character $character;

    public function render(CharacterSyncService $sync, TrainingCalculator $calculator): View
    {
        try {
            $sync->sync($this->character);
            $this->character->refresh();
        } catch (EsiErrorLimited|EsiRequestFailed) {
            // A failed poll refresh is not fatal; the card renders stored data.
        }

        $current = DB::table('character_skill_queue as q')
            ->leftJoin('item_types as t', 't.type_id', '=', 'q.skill_id')
            ->leftJoin('skill_types as s', 's.type_id', '=', 'q.skill_id')
            ->where('q.character_id', $this->character->character_id)
            ->orderBy('q.position')
            ->select('q.*', 't.name', 's.rank', 's.primary_attribute', 's.secondary_attribute')
            ->first();

        $spPerHour = null;
        $progress = null;
        $levelSpDone = null;
        $levelSpTotal = null;

        if ($current !== null && $current->primary_attribute !== null) {
            $spPerHour = $calculator->spPerMinute(
                (int) $this->character->{$current->primary_attribute},
                (int) $this->character->{$current->secondary_attribute},
            ) * 60;
        }

        if ($current !== null && $current->start_date !== null && $current->finish_date !== null) {
            $start = strtotime($current->start_date);
            $finish = strtotime($current->finish_date);

            if ($finish > $start) {
                $fraction = min(1, max(0, (time() - $start) / ($finish - $start)));
                $progress = $fraction;

                if ($current->level_start_sp !== null && $current->level_end_sp !== null) {
                    $trainingStart = (int) ($current->training_start_sp ?? $current->level_start_sp);
                    $spNow = $trainingStart + $fraction * ((int) $current->level_end_sp - $trainingStart);
                    $levelSpDone = (int) round($spNow - (int) $current->level_start_sp);
                    $levelSpTotal = (int) $current->level_end_sp - (int) $current->level_start_sp;
                }
            }
        }

        return view('livewire.training-status', [
            'current' => $current,
            'paused' => $current !== null && $current->start_date === null,
            'progress' => $progress,
            'spPerHour' => $spPerHour,
            'levelSpDone' => $levelSpDone,
            'levelSpTotal' => $levelSpTotal,
        ]);
    }
}
