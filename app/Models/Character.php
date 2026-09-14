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

    public const ROUTE_SECURITY_LABELS = [
        'highsec' => 'High-sec only',
        'highlow' => 'High + Low-sec',
        'all' => 'Anywhere',
    ];

    /**
     * Lowest security status routing may pass through, or null for no limit.
     * High-sec is 0.5+; the 0.45 threshold matches the in-game rounding.
     * High+Low keeps low-sec (0.1–0.4) but drops null-sec (≤ 0.0).
     */
    public function minRouteSecurity(): ?float
    {
        return match ($this->route_security ?? 'highsec') {
            'highsec' => 0.45,
            'highlow' => 0.05,
            default => null,
        };
    }

    public function routeSecurityLabel(): string
    {
        return self::ROUTE_SECURITY_LABELS[$this->route_security ?? 'highsec'] ?? 'High-sec only';
    }
}
