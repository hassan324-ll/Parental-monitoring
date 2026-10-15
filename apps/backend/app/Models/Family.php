<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    protected $fillable = ['name'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
