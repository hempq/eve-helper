<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillPlanTarget extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SkillPlan::class, 'plan_id');
    }
}
