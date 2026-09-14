<?php

namespace App\Services\Skills;

use App\Models\Character;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expands a list of goal skills into a full, ordered training plan: every
 * prerequisite (recursively) precedes the level that needs it, and levels the
 * character already has are skipped. Partial SP trained into the next level
 * is credited.
 */
class PlanBuilder
{
    public function __construct(private readonly TrainingCalculator $calculator) {}

    /** @var array<int, int> planned level so far per skill id (during build) */
    private array $planned = [];

    /** @var list<array{skill_id: int, level: int, prereq: bool}> */
    private array $steps = [];

    /**
     * @param  iterable<object{skill_id: int, target_level: int}>  $targets
     * @return Collection<int, PlanEntry>
     */
    public function build(Character $character, iterable $targets): Collection
    {
        $this->planned = [];
        $this->steps = [];

        $trained = DB::table('character_skills')
            ->where('character_id', $character->character_id)
            ->pluck('trained_level', 'skill_id')
            ->map(intval(...))
            ->all();

        foreach ($targets as $target) {
            $this->resolve((int) $target->skill_id, (int) $target->target_level, $trained, isPrereq: false, visiting: []);
        }

        return $this->hydrate($character, $trained);
    }

    /**
     * @param  array<int, int>  $trained
     * @param  list<int>  $visiting  cycle guard
     */
    private function resolve(int $skillId, int $targetLevel, array $trained, bool $isPrereq, array $visiting): void
    {
        if (in_array($skillId, $visiting, true)) {
            return; // broken SDE data would loop forever; bail out
        }

        $have = max($trained[$skillId] ?? 0, $this->planned[$skillId] ?? 0);

        if ($have >= $targetLevel) {
            return;
        }

        // Prerequisites gate injecting the skill book, i.e. training level 1.
        if ($have === 0) {
            $prerequisites = DB::table('skill_prerequisites')
                ->where('skill_id', $skillId)
                ->get(['required_skill_id', 'required_level']);

            foreach ($prerequisites as $prerequisite) {
                $this->resolve(
                    (int) $prerequisite->required_skill_id,
                    (int) $prerequisite->required_level,
                    $trained,
                    isPrereq: true,
                    visiting: [...$visiting, $skillId],
                );
            }
        }

        for ($level = $have + 1; $level <= $targetLevel; $level++) {
            $this->steps[] = ['skill_id' => $skillId, 'level' => $level, 'prereq' => $isPrereq];
        }

        $this->planned[$skillId] = $targetLevel;
    }

    /**
     * @param  array<int, int>  $trained
     * @return Collection<int, PlanEntry>
     */
    private function hydrate(Character $character, array $trained): Collection
    {
        if ($this->steps === []) {
            return collect();
        }

        $skillIds = array_unique(array_column($this->steps, 'skill_id'));

        $meta = DB::table('skill_types as s')
            ->join('item_types as t', 't.type_id', '=', 's.type_id')
            ->whereIn('s.type_id', $skillIds)
            ->get(['s.type_id', 's.rank', 's.primary_attribute', 's.secondary_attribute', 't.name'])
            ->keyBy('type_id');

        $partialSp = DB::table('character_skills')
            ->where('character_id', $character->character_id)
            ->whereIn('skill_id', $skillIds)
            ->pluck('skillpoints', 'skill_id')
            ->all();

        return collect($this->steps)
            ->map(function (array $step) use ($meta, $trained, $partialSp) {
                $info = $meta->get($step['skill_id']);

                if ($info === null) {
                    return null; // skill missing from SDE; drop silently
                }

                $rank = (int) $info->rank;
                $needed = $this->calculator->spBetweenLevels($rank, $step['level'] - 1, $step['level']);

                // Credit SP already trained into this level.
                if (($trained[$step['skill_id']] ?? 0) === $step['level'] - 1) {
                    $partial = (int) ($partialSp[$step['skill_id']] ?? 0)
                        - $this->calculator->cumulativeSp($rank, $step['level'] - 1);
                    $needed = max(0, $needed - max(0, $partial));
                }

                return new PlanEntry(
                    skillId: (int) $step['skill_id'],
                    name: $info->name,
                    level: $step['level'],
                    sp: $needed,
                    rank: $rank,
                    primaryAttribute: $info->primary_attribute,
                    secondaryAttribute: $info->secondary_attribute,
                    isPrerequisite: $step['prereq'],
                );
            })
            ->filter()
            ->values();
    }
}
