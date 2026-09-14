<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    protected $table = 'alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
