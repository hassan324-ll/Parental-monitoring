<?php

namespace App\Auth;

final readonly class VerifiedFirebaseParent
{
    public function __construct(
        public string $uid,
        public string $email,
        public string $name,
    ) {}
}
