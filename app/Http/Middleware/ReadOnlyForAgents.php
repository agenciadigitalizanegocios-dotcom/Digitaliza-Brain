<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Corretor só visualiza o cérebro: qualquer envio (salvar, excluir, restaurar...) recebe 403. */
class ReadOnlyForAgents
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === User::ROLE_AGENT && ! $request->isMethodSafe()) {
            abort(403, 'Corretor só pode visualizar o cérebro.');
        }

        return $next($request);
    }
}
