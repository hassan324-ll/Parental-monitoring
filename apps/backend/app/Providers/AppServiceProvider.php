<?php

namespace App\Providers;

use App\Auth\FirebaseParentUserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::viaRequest('firebase-parent', function (Request $request) {
            return app(FirebaseParentUserResolver::class)->resolve($request->bearerToken());
        });
    }
}
