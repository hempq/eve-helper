<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterSkillQueueEntry extends Model
{
    protected $table = 'character_skill_queue';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_datetime',
            'finish_date' => 'immutable_datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }
}
