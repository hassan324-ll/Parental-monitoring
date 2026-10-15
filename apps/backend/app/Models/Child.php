<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Child extends Model
{
    protected $fillable = ['display_name'];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
