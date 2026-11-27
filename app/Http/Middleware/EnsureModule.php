<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Só deixa passar se o módulo (ex.: catalogo) estiver ligado para o cliente atual. Uso: module:catalogo */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant && ($tenant->moduleFlags()[$module] ?? false), 404);

        return $next($request);
    }
}
