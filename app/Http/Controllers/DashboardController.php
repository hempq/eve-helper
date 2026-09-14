<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\Characters\CharacterSyncService;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Skills\QueueAnalysisService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function show(
        Request $request,
        CharacterSyncService $sync,
        QueueAnalysisService $analysis,
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

        $skillStats = DB::table('character_skills')
            ->where('character_id', $character->character_id)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN trained_level = 5 THEN 1 ELSE 0 END) as at_five')
            ->first();

        return view('dashboard', [
            'character' => $character,
            'queue' => $queue,
            'queueEndsAt' => $queue->whereNotNull('finish_date')->last()?->finish_date,
            'queuePaused' => $current !== null && $current->start_date === null,
            'skillCount' => (int) ($skillStats->total ?? 0),
            'skillsAtFive' => (int) ($skillStats->at_five ?? 0),
            'implantBonuses' => $analysis->implantBonuses($character),
            'implants' => DB::table('character_implants as ci')
                ->join('item_types as t', 't.type_id', '=', 'ci.type_id')
                ->where('ci.character_id', $character->character_id)
                ->orderBy('t.name')
                ->pluck('t.name'),
            'syncError' => $syncError,
        ]);
    }
}
