<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillPlan extends Model
{
    protected $guarded = [];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SkillPlanTarget::class, 'plan_id')->orderBy('position');
    }

    public static function defaultFor(Character $character): self
    {
        return self::firstOrCreate(
            ['character_id' => $character->character_id],
            ['name' => 'Training plan'],
        );
    }
}
