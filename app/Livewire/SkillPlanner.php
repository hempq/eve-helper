<?php

namespace App\Livewire;

use App\Models\Character;
use App\Models\SkillPlan;
use App\Services\Skills\PlanBuilder;
use App\Services\Skills\QueueAnalysisService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SkillPlanner extends Component
{
    public Character $character;

    public string $search = '';

    public int $level = 5;

    public function addSkill(int $skillId): void
    {
        $plan = SkillPlan::defaultFor($this->character);

        $existing = $plan->targets()->where('skill_id', $skillId)->first();

        if ($existing !== null) {
            $existing->update(['target_level' => max((int) $existing->target_level, $this->level)]);
        } else {
            $plan->targets()->create([
                'skill_id' => $skillId,
                'target_level' => $this->level,
                'position' => ((int) $plan->targets()->max('position')) + 1,
            ]);
        }

        $this->search = '';
    }

    public function removeTarget(int $targetId): void
    {
        SkillPlan::defaultFor($this->character)->targets()->whereKey($targetId)->delete();
    }

    public function clearPlan(): void
    {
        SkillPlan::defaultFor($this->character)->targets()->delete();
    }

    public function render(PlanBuilder $builder, QueueAnalysisService $analysis): View
    {
        $plan = SkillPlan::defaultFor($this->character);
        $targets = $plan->targets()->get();

        $targetNames = $targets->isEmpty() ? collect() : DB::table('item_types')
            ->whereIn('type_id', $targets->pluck('skill_id'))
            ->pluck('name', 'type_id');

        $entries = $builder->build($this->character, $targets);
        $buckets = $analysis->bucketsFromEntries($entries);

        $report = $buckets === []
            ? null
            : $analysis->reportFromBuckets($this->character, $buckets, $entries->count());

        return view('livewire.skill-planner', [
            'results' => $this->searchResults(),
            'targets' => $targets,
            'targetNames' => $targetNames,
            'entries' => $entries,
            'report' => $report,
            'unallocatedSp' => (int) ($this->character->unallocated_sp ?? 0),
        ]);
    }

    private function searchResults(): \Illuminate\Support\Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        return DB::table('skill_types as s')
            ->join('item_types as t', 't.type_id', '=', 's.type_id')
            ->where('t.published', true)
            ->where('t.name', 'like', '%'.trim($this->search).'%')
            ->orderBy('t.name')
            ->limit(10)
            ->get(['s.type_id', 't.name', 's.rank', 's.primary_attribute', 's.secondary_attribute']);
    }
}
