<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A API só serve o n8n, que chama por dentro do Docker (http://cerebro:8000).
 * Tudo que passa pelo túnel do Cloudflare chega com Cf-Connecting-Ip / Cf-Ray, e o
 * Cloudflare não deixa o visitante apagar esses cabeçalhos. Esses pedidos recebem 404.
 */
class InternalApiOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cerebro.api_allow_public')
            && ($request->headers->has('Cf-Connecting-Ip') || $request->headers->has('Cf-Ray'))) {
            return response()->json(['message' => 'Não encontrado.'], 404);
        }

        return $next($request);
    }
}
