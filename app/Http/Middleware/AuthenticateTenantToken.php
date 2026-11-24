<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API do n8n: identifica a imobiliária pelo token Bearer. */
class AuthenticateTenantToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::findByApiToken($request->bearerToken());

        if (! $tenant) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        app(CurrentTenant::class)->set($tenant);

        return $next($request);
    }
}
