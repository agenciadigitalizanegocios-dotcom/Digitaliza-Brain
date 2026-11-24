<?php

namespace Tests\Feature;

use App\Brain\TemplateApplier;
use App\Models\AuditLog;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Correções da revisão de segurança da Parte 1. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Tenant $a;

    private User $owner;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Imob A', 'slug' => 'imob-a', 'status' => 'ativo']);
        TemplateApplier::provision($this->a, 'imobiliaria');
        $this->owner = User::create(['tenant_id' => $this->a->id, 'name' => 'Dono', 'email' => 'dono@x.com', 'password' => 'secret123', 'role' => 'dono']);
        $this->agent = User::create(['tenant_id' => $this->a->id, 'name' => 'Corretor', 'email' => 'corretor@x.com', 'password' => 'secret123', 'role' => 'corretor']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'secret123', 'role' => 'admin']);
        app(CurrentTenant::class)->set(null);
    }

    // Item 7 — corretor só visualiza.
    public function test_corretor_so_visualiza(): void
    {
        $this->actingAs($this->agent)->get('/cerebro/faq')->assertOk()->assertDontSee('data-open-modal', false);
        $this->actingAs($this->agent)->post('/cerebro/faq/itens', ['fields' => ['question' => 'P', 'answer' => 'R']])->assertForbidden();
        $this->actingAs($this->agent)->put('/cerebro/negocio', ['fields' => ['company_name' => 'X']])->assertForbidden();
        $this->actingAs($this->agent)->post('/cerebro/versoes/1/restaurar')->assertForbidden();
        $this->assertSame(0, BrainItem::withoutGlobalScopes()->where('tenant_id', $this->a->id)->where('section', 'faq')->count());

        // O dono continua editando.
        $this->actingAs($this->owner)->post('/cerebro/faq/itens', ['fields' => ['question' => 'P', 'answer' => 'R']])->assertRedirect();
    }

    // Item 2 — API só pela rede interna.
    public function test_api_recusa_pedido_que_veio_pelo_cloudflare(): void
    {
        $token = $this->a->generateApiToken();

        $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}", 'Cf-Connecting-Ip' => '200.1.2.3'])->assertNotFound();
        $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}", 'Cf-Ray' => 'abc'])->assertNotFound();
        $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    // Item 1 — limite por e-mail, mesmo trocando de IP.
    public function test_login_bloqueia_por_email_mesmo_trocando_de_ip(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->post('/login', ['email' => 'dono@x.com', 'password' => 'errada']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post('/login', ['email' => 'dono@x.com', 'password' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'login_bloqueado')->exists());
    }

    // Turnstile no login: só exigido após as falhas.
    public function test_turnstile_so_aparece_apos_falhas_e_valida_token(): void
    {
        config(['cerebro.turnstile' => ['enabled' => true, 'site_key' => 'sk', 'secret_key' => 'sec', 'after_failures' => 2]]);
        \Illuminate\Support\Facades\Http::fake(['challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['success' => false])->push(['success' => true])]);

        $this->get('/login')->assertDontSee('cf-turnstile');
        for ($i = 0; $i < 2; $i++) {
            $this->post('/login', ['email' => 'dono@x.com', 'password' => 'errada']);
        }
        $this->get('/login')->assertSee('cf-turnstile', false);

        // Sem token: barrado.
        $this->post('/login', ['email' => 'dono@x.com', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        // Token recusado pela Cloudflare: barrado.
        $this->post('/login', ['email' => 'dono@x.com', 'password' => 'secret123', 'cf-turnstile-response' => 'ruim'])->assertSessionHasErrors('email');
        $this->assertGuest();
        // Token aceito: entra.
        $this->post('/login', ['email' => 'dono@x.com', 'password' => 'secret123', 'cf-turnstile-response' => 'bom']);
        $this->assertAuthenticated();
    }

    // Item 6 — cabeçalhos.
    public function test_cabecalhos_de_seguranca(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $this->get('/login')->headers->get('Content-Security-Policy'));
    }

    // Item 4 — limite de itens por aba.
    public function test_limite_de_itens_por_aba(): void
    {
        config(['cerebro.limits.items_per_tab' => 2]);
        foreach (['P1', 'P2'] as $q) {
            $this->actingAs($this->owner)->post('/cerebro/faq/itens', ['fields' => ['question' => $q, 'answer' => 'R']])->assertRedirect();
        }
        $this->actingAs($this->owner)->from('/cerebro/faq')->post('/cerebro/faq/itens', ['fields' => ['question' => 'P3', 'answer' => 'R']])
            ->assertSessionHasErrors('limite');
        $this->assertSame(2, BrainItem::withoutGlobalScopes()->where('tenant_id', $this->a->id)->where('section', 'faq')->count());
    }

    // Item 4 — prompt grande demais desfaz a alteração inteira.
    public function test_prompt_grande_demais_nao_salva_nada(): void
    {
        config(['cerebro.limits.prompt_chars' => 500]);
        $this->actingAs($this->owner)->from('/cerebro/faq')->post('/cerebro/faq/itens', ['fields' => [
            'question' => 'Pergunta longa', 'answer' => str_repeat('resposta ', 100),
        ]])->assertSessionHasErrors('limite');

        $this->assertSame(0, BrainItem::withoutGlobalScopes()->where('tenant_id', $this->a->id)->where('section', 'faq')->count());
        $this->assertSame(0, (int) BrainSetting::withoutGlobalScopes()->where('tenant_id', $this->a->id)->value('version'));
    }

    // Item 5 — cota de imagens.
    public function test_cota_de_imagens(): void
    {
        Storage::fake('public');
        $b = Tenant::create(['name' => 'Escola', 'slug' => 'escola', 'status' => 'ativo']);
        TemplateApplier::provision($b, 'escola-tecnica');
        $user = User::create(['tenant_id' => $b->id, 'name' => 'Dono B', 'email' => 'b@x.com', 'password' => 'secret123', 'role' => 'dono']);

        config(['cerebro.limits.images_mb' => 0.00001]); // ~10 bytes
        $this->actingAs($user)->from('/cerebro/cursos')->post('/cerebro/cursos/itens', ['fields' => [
            'nome' => 'Curso', 'foto' => UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG)),
        ]])->assertSessionHasErrors('fields.foto');
        $this->assertSame([], Storage::disk('public')->allFiles('tenants'));
    }

    // Item 9 — campo arquivado não sai em settings.
    public function test_api_nao_devolve_campo_arquivado_de_formulario(): void
    {
        $this->actingAs($this->owner)->put('/cerebro/negocio', ['fields' => ['company_name' => 'A', 'about' => 'Texto secreto antigo']])->assertRedirect();
        $this->actingAs($this->admin)->withSession(['admin_tenant_id' => $this->a->id])
            ->patch("/admin/clientes/{$this->a->id}/estrutura/abas/negocio/campos/about/arquivar")->assertSessionHasErrors('structure'); // usado pelo formato

        // Campo novo, arquivado depois.
        $base = "/admin/clientes/{$this->a->id}/estrutura";
        $this->actingAs($this->admin)->post("$base/abas/negocio/campos", ['label' => 'Nota', 'type' => 'text'])->assertRedirect();
        $this->actingAs($this->owner)->put('/cerebro/negocio', ['fields' => ['company_name' => 'A', 'nota' => 'não mostrar']])->assertRedirect();
        $this->actingAs($this->admin)->patch("$base/abas/negocio/campos/nota/arquivar")->assertRedirect();

        $token = $this->a->fresh()->generateApiToken();
        $json = $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}"])->assertOk()->json();
        $this->assertArrayNotHasKey('nota', $json['settings']);
        $this->assertSame('A', $json['settings']['company_name']);
    }

    // Item 10 — registro das ações do admin.
    public function test_registro_das_acoes_do_admin(): void
    {
        $this->actingAs($this->admin)->post("/admin/clientes/{$this->a->id}/token")->assertRedirect();
        $this->actingAs($this->admin)->patch("/admin/clientes/{$this->a->id}/status")->assertRedirect();

        $this->assertTrue(AuditLog::where('action', 'token_gerado')->where('tenant_id', $this->a->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'cliente_suspenso')->exists());
        $this->actingAs($this->admin)->get('/admin/registro')->assertOk()->assertSee('Gerou token novo do n8n');
        $this->actingAs($this->owner)->get('/admin/registro')->assertForbidden();
    }
}
