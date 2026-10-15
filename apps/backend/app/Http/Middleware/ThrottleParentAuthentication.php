<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/** Bound authentication work before resolving any bearer identity. */
class ThrottleParentAuthentication extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        return hash('sha256', 'firebase-parent|'.$request->ip());
    }
}
