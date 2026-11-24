<?php

namespace App\Http\Controllers\Admin;

use App\Brain\Modules;
use App\Brain\TemplateApplier;
use App\Http\Controllers\Controller;
use App\Models\BrainTemplate;
use App\Models\BrainVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Painel admin da Digitaliza: clientes, entrar no painel de um cliente, token do n8n. */
class TenantController extends Controller
{
    public function index()
    {
        $versions = BrainVersion::withoutGlobalScopes()
            ->selectRaw('tenant_id, max(version) as version, max(created_at) as updated_at')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        return view('admin.tenants', [
            'tenants' => Tenant::withCount('users')->with('template')->orderBy('name')->get(),
            'versions' => $versions,
            'templates' => BrainTemplate::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template' => ['required', 'exists:brain_templates,slug'],
            'plan' => ['nullable', 'string', 'max:40'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:8'],
        ], [], [
            'name' => 'nome do cliente',
            'template' => 'modelo',
            'owner_name' => 'nome do dono',
            'owner_email' => 'e-mail do dono',
            'owner_password' => 'senha',
        ]);

        [$tenant, $token] = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => Tenant::uniqueSlug($data['name']),
                'status' => Tenant::ACTIVE,
                'plan' => $data['plan'] ?? null,
            ]);

            User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
                'role' => User::ROLE_OWNER,
            ]);

            // Copia a estrutura do modelo escolhido (e os itens iniciais).
            TemplateApplier::provision($tenant, $data['template']);

            return [$tenant, $tenant->generateApiToken()];
        });

        Audit::log('cliente_criado', $tenant->id, ['nome' => $tenant->name, 'modelo' => $data['template']]);

        return redirect()->route('admin.tenants.index')
            ->with('ok', "Cliente {$tenant->name} criado.")
            ->with('token', ['tenant' => $tenant->name, 'value' => $token]);
    }

    public function enter(Request $request, Tenant $tenant)
    {
        $request->session()->put('admin_tenant_id', $tenant->id);
        Audit::log('cliente_aberto', $tenant->id);

        return redirect()->route('brain.index');
    }

    public function leave(Request $request)
    {
        $request->session()->forget('admin_tenant_id');

        return redirect()->route('admin.tenants.index');
    }

    public function token(Tenant $tenant)
    {
        $token = $tenant->generateApiToken();
        Audit::log('token_gerado', $tenant->id);

        return redirect()->route('admin.tenants.index')
            ->with('ok', "Token novo gerado para {$tenant->name}. O antigo parou de funcionar.")
            ->with('token', ['tenant' => $tenant->name, 'value' => $token]);
    }

    public function modules(Request $request, Tenant $tenant)
    {
        $flags = [];
        foreach (Modules::ALL as $key => $label) {
            $flags[$key] = $request->boolean('modules.'.$key);
        }
        $tenant->update(['modules' => $flags]);
        Audit::log('modulos', $tenant->id, $flags);

        // A API devolve "modules" fora do cache, então vale na próxima mensagem.
        return redirect()->route('admin.tenants.index')->with('ok', "Módulos de {$tenant->name} salvos.");
    }

    public function update(Tenant $tenant)
    {
        abort_unless($tenant->template, 404);

        return view('admin.update', [
            'tenant' => $tenant,
            'template' => $tenant->template,
            'changes' => TemplateApplier::changes($tenant),
        ]);
    }

    public function applyUpdate(Request $request, Tenant $tenant)
    {
        $request->validate(['changes' => ['nullable', 'array'], 'changes.*' => ['string']]);
        TemplateApplier::applyUpdate($tenant, $request->input('changes', []), $request->user());
        Audit::log('atualizacao_aplicada', $tenant->id, ['mudancas' => count($request->input('changes', []))]);

        return redirect()->route('admin.tenants.index')->with('ok', "Atualização do modelo aplicada em {$tenant->name}.");
    }

    public function dismissUpdate(Tenant $tenant)
    {
        TemplateApplier::dismiss($tenant);
        Audit::log('atualizacao_dispensada', $tenant->id);

        return redirect()->route('admin.tenants.index')->with('ok', "Atualização dispensada para {$tenant->name}. Nada mudou no cliente.");
    }

    public function status(Tenant $tenant)
    {
        $tenant->update([
            'status' => $tenant->isActive() ? Tenant::SUSPENDED : Tenant::ACTIVE,
        ]);
        Audit::log($tenant->isActive() ? 'cliente_reativado' : 'cliente_suspenso', $tenant->id);

        return redirect()->route('admin.tenants.index')->with('ok', $tenant->isActive()
            ? "{$tenant->name} reativado: o robô volta a responder."
            : "{$tenant->name} suspenso: o robô para de responder.");
    }
}
