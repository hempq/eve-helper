<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\Characters\CharacterSyncService;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Skills\TrainingCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function show(
        Request $request,
        CharacterSyncService $sync,
        TrainingCalculator $calculator,
    ): View {
        $character = ($id = $request->session()->get('character_id')) !== null
            ? Character::find($id)
            : null;

        if ($character === null) {
            return view('home');
        }

        $syncError = null;

        try {
            $sync->sync($character);
            $character->refresh();
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $syncError = $e->getMessage();
        }

        $queue = DB::table('character_skill_queue as q')
            ->leftJoin('item_types as t', 't.type_id', '=', 'q.skill_id')
            ->leftJoin('skill_types as s', 's.type_id', '=', 'q.skill_id')
            ->where('q.character_id', $character->character_id)
            ->orderBy('q.position')
            ->select('q.*', 't.name', 's.rank', 's.primary_attribute', 's.secondary_attribute')
            ->get();

        $current = $queue->first();
        $currentSpPerHour = null;
        $currentProgress = null;

        if ($current !== null && $current->primary_attribute !== null) {
            $currentSpPerHour = $calculator->spPerMinute(
                (int) $character->{$current->primary_attribute},
                (int) $character->{$current->secondary_attribute},
            ) * 60;
        }

        if ($current !== null && $current->start_date !== null && $current->finish_date !== null) {
            $start = strtotime($current->start_date);
            $finish = strtotime($current->finish_date);
            $currentProgress = $finish > $start
                ? min(1, max(0, (time() - $start) / ($finish - $start)))
                : null;
        }

        return view('dashboard', [
            'character' => $character,
            'queue' => $queue,
            'current' => $current,
            'currentSpPerHour' => $currentSpPerHour,
            'currentProgress' => $currentProgress,
            'queueEndsAt' => $queue->last()?->finish_date,
            'queuePaused' => $current !== null && $current->start_date === null,
            'syncError' => $syncError,
        ]);
    }
}
