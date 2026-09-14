<?php

namespace App\Services\Skills;

use App\Models\Character;
use Illuminate\Support\Facades\DB;

/**
 * Turns a character's live skill queue into training buckets and produces
 * the remap recommendation for it.
 */
class QueueAnalysisService
{
    public function __construct(private readonly RemapOptimizer $optimizer) {}

    /**
     * Returns null when the queue holds no optimizable SP (empty queue or
     * missing SDE data).
     */
    public function remapReport(Character $character): ?RemapReport
    {
        $rows = DB::table('character_skill_queue as q')
            ->join('skill_types as s', 's.type_id', '=', 'q.skill_id')
            ->where('q.character_id', $character->character_id)
            ->whereNotNull('q.level_end_sp')
            ->orderBy('q.position')
            ->select('q.position', 'q.level_start_sp', 'q.level_end_sp', 'q.training_start_sp',
                's.primary_attribute', 's.secondary_attribute')
            ->get();

        $bucketSp = [];

        foreach ($rows as $row) {
            $startSp = (int) $row->position === 0 && $row->training_start_sp !== null
                ? max((int) $row->training_start_sp, (int) $row->level_start_sp)
                : (int) $row->level_start_sp;

            $sp = (int) $row->level_end_sp - $startSp;

            if ($sp <= 0) {
                continue;
            }

            $key = $row->primary_attribute.'|'.$row->secondary_attribute;
            $bucketSp[$key] = ($bucketSp[$key] ?? 0) + $sp;
        }

        if ($bucketSp === []) {
            return null;
        }

        $buckets = [];
        foreach ($bucketSp as $key => $sp) {
            [$primary, $secondary] = explode('|', $key);
            $buckets[] = new TrainingBucket($primary, $secondary, $sp);
        }

        $current = new AttributeSet(
            (int) $character->charisma,
            (int) $character->intelligence,
            (int) $character->memory,
            (int) $character->perception,
            (int) $character->willpower,
        );

        $bonuses = $this->implantBonuses($character);
        $base = $current->subtract($bonuses);

        $optimal = $this->optimizer->optimize($buckets, $bonuses);

        return new RemapReport(
            totalSp: array_sum($bucketSp),
            skillCount: $rows->count(),
            buckets: $buckets,
            currentAttributes: $current,
            implantBonuses: $bonuses,
            currentBase: $base,
            optimalBase: $optimal->baseAttributes,
            currentMinutes: $this->optimizer->minutesFor($buckets, $current),
            optimalMinutes: $optimal->minutes,
            bonusRemaps: (int) ($character->bonus_remaps ?? 0),
            nextYearlyRemapAt: $character->last_remap_date?->addYear(),
        );
    }

    public function implantBonuses(Character $character): AttributeSet
    {
        $bonuses = DB::table('character_implants as ci')
            ->join('implant_bonuses as ib', 'ib.type_id', '=', 'ci.type_id')
            ->where('ci.character_id', $character->character_id)
            ->selectRaw('ib.attribute, SUM(ib.bonus) as total')
            ->groupBy('ib.attribute')
            ->pluck('total', 'attribute')
            ->all();

        return new AttributeSet(
            (int) ($bonuses['charisma'] ?? 0),
            (int) ($bonuses['intelligence'] ?? 0),
            (int) ($bonuses['memory'] ?? 0),
            (int) ($bonuses['perception'] ?? 0),
            (int) ($bonuses['willpower'] ?? 0),
        );
    }
}
