<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    /** @use HasFactory<\Database\Factories\CharacterFactory> */
    use HasFactory;

    protected $primaryKey = 'character_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'immutable_datetime',
            'last_remap_date' => 'immutable_datetime',
            'accrued_remap_cooldown_date' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class, 'character_id');
    }

    public function skillQueue(): HasMany
    {
        return $this->hasMany(CharacterSkillQueueEntry::class, 'character_id')->orderBy('position');
    }
}
