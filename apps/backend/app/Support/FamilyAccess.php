<?php

namespace App\Support;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class FamilyAccess
{
    public static function visibleTo(User $user): Builder
    {
        return Family::query()->where(function (Builder $query) use ($user): void {
            $query->where('owner_id', $user->getKey())
                ->orWhereHas('members', function (Builder $members) use ($user): void {
                    $members->where('users.id', $user->getKey());
                });
        });
    }

    public static function findVisible(User $user, Family $family): Family
    {
        return self::visibleTo($user)->whereKey($family->getKey())->firstOrFail();
    }

    public static function canManage(User $user, Family $family): bool
    {
        if ((int) $family->owner_id === (int) $user->getKey()) {
            return true;
        }

        return $family->members()
            ->where('users.id', $user->getKey())
            ->wherePivotIn('role', ['owner', 'parent'])
            ->exists();
    }
}
