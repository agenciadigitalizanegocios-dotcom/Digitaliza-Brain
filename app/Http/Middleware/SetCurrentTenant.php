<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define a imobiliária do painel.
 * Dono/corretor: a imobiliária dele. Admin da Digitaliza: a que ele escolheu na lista de clientes.
 */
class SetCurrentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $id = $request->session()->get('admin_tenant_id');
            $tenant = $id ? Tenant::find($id) : null;

            if (! $tenant) {
                return redirect()->route('admin.tenants.index')
                    ->with('info', 'Escolha um cliente para abrir o cérebro dele.');
            }
        } else {
            $tenant = $user->tenant;
            abort_unless($tenant, 403, 'Seu usuário não está ligado a nenhuma imobiliária.');
        }

        app(CurrentTenant::class)->set($tenant);
        View::share('currentTenant', $tenant);
        // Corretor só visualiza: as telas escondem os botões de editar (e o ReadOnlyForAgents barra no servidor).
        View::share('canEdit', $user->role !== \App\Models\User::ROLE_AGENT);

        return $next($request);
    }
}
