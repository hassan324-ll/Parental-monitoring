<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FirebaseParentUserResolver
{
    public function __construct(private readonly FirebaseTokenVerifier $verifier) {}

    /**
     * Bind a validated Firebase UID to a single local parent identity.
     * Never auto-link an unrelated Laravel account using email alone.
     */
    public function resolve(?string $token): ?User
    {
        $identity = $this->verifier->verify($token);
        if ($identity === null) {
            return null;
        }

        $existing = User::query()->where('firebase_uid', $identity->uid)->first();
        if ($existing !== null) {
            return $existing;
        }

        if (User::query()->where('email', $identity->email)->exists()) {
            return null;
        }

        try {
            $parent = new User();
            $parent->name = $identity->name;
            $parent->email = $identity->email;
            $parent->firebase_uid = $identity->uid;
            $parent->email_verified_at = now();
            $parent->password = Hash::make(Str::random(64));
            $parent->save();

            return $parent;
        } catch (QueryException) {
            // Another request may have created the same UID concurrently.
            return User::query()->where('firebase_uid', $identity->uid)->first();
        }
    }
}
