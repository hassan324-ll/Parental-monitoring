<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePairedDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $device = Device::query()
            ->where('credential_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->first();

        if (! $device) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set('paired_device', $device);
        return $next($request);
    }
}
