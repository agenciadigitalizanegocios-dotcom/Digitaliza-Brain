<?php

namespace Tests\Feature;

use App\Brain\StructureValidator;
use App\Brain\TemplateApplier;
use App\Brain\Templates;
use App\Fields\FieldTypes;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\BrainTemplate;
use App\Models\BrainVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Parte 1: abas e campos por cliente, modelos, atualização pendente, dinheiro e imagem. */
class StructureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $a;

    private Tenant $b;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'secret123', 'role' => 'admin']);
        $this->a = Tenant::create(['name' => 'Imob A', 'slug' => 'imob-a', 'status' => 'ativo']);
        $this->b = Tenant::create(['name' => 'Escola B', 'slug' => 'escola-b', 'status' => 'ativo']);
        $this->ownerA = User::create(['tenant_id' => $this->a->id, 'name' => 'Dono A', 'email' => 'a@x.com', 'password' => 'secret123', 'role' => 'dono']);
        TemplateApplier::provision($this->a, 'imobiliaria');
        TemplateApplier::provision($this->b, 'escola-tecnica');
        app(CurrentTenant::class)->set(null);
    }

    private function structureOf(Tenant $t): array
    {
        return BrainSetting::withoutGlobalScopes()->where('tenant_id', $t->id)->first()->structure;
    }

    private function enterAs(Tenant $t)
    {
        return $this->actingAs($this->admin)->withSession(['admin_tenant_id' => $t->id]);
    }

    // 12.1 — a BS do seed sai idêntica ao sistema antigo.
    public function test_bs_do_seed_tem_prompt_e_api_identicos_aos_de_antes(): void
    {
        $this->seed();
        $bs = Tenant::where('slug', 'bs-imoveis')->firstOrFail();
        $token = $bs->generateApiToken();

        $json = $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}"])->assertOk()->json();
        $this->assertSame(['catalogo' => false, 'agenda' => false], $json['modules']);
        unset($json['updated_at'], $json['modules']);

        $expected = json_decode(file_get_contents(base_path('tests/fixtures/parte1/bs_api.json')), true);
        $expected['tenant']['id'] = $bs->id;
        $this->assertEquals($expected, $json);
        $this->assertSame(file_get_contents(base_path('tests/fixtures/parte1/bs_prompt.txt')), $json['prompt']);

        // A primeira versão já guarda a estrutura.
        $v1 = BrainVersion::withoutGlobalScopes()->where('tenant_id', $bs->id)->where('version', 1)->first();
        $this->assertNotEmpty($v1->snapshot['structure']['tabs']);
    }

    // 12.14 — os três modelos passam no validador.
    public function test_os_tres_modelos_sao_validos(): void
    {
        foreach (array_keys(Templates::FILES) as $slug) {
            $this->assertSame([], StructureValidator::validate(Templates::file($slug)), $slug);
            $this->assertTrue(BrainTemplate::where('slug', $slug)->exists(), $slug);
        }
    }

    // 12.3 — cliente recebe uma cópia; editar o modelo não muda o cliente.
    public function test_editar_modelo_nao_muda_o_cliente_e_marca_pendente(): void
    {
        $template = BrainTemplate::where('slug', 'imobiliaria')->first();
        $before = $this->structureOf($this->a);

        $this->actingAs($this->admin)
            ->put("/admin/modelos/{$template->id}/estrutura/abas/faq", [
                'label' => 'Dúvidas', 'icon' => '💬', 'intro' => 'x', 'ai_format' => 'faq', 'ai_title' => 'PERGUNTAS FREQUENTES', 'ai_order' => 10,
            ])->assertRedirect();

        $this->assertSame(2, $template->fresh()->version);
        $this->assertEquals($before, $this->structureOf($this->a));
        $this->assertTrue($this->a->fresh()->hasPendingUpdate());

        // Aplicar: muda o rótulo, gera versão, tira o selo.
        $changes = TemplateApplier::changes($this->a->fresh());
        $ids = array_column(array_filter($changes, fn ($c) => $c['checked']), 'id');
        $this->actingAs($this->admin)->post("/admin/clientes/{$this->a->id}/atualizacao", ['changes' => $ids])->assertRedirect();

        $faq = collect($this->structureOf($this->a)['tabs'])->firstWhere('key', 'faq');
        $this->assertSame('Dúvidas', $faq['label']);
        $this->assertFalse($this->a->fresh()->hasPendingUpdate());
    }

    // 12.4 — personalização do cliente vem desmarcada; dispensar tira o selo sem mudar nada.
    public function test_personalizacao_vem_desmarcada_e_dispensar_nao_muda_nada(): void
    {
        $this->enterAs($this->a)->put("/admin/clientes/{$this->a->id}/estrutura/abas/servicos", [
            'label' => 'Taxas da A', 'icon' => '💲', 'ai_format' => 'servicos', 'ai_title' => 'SERVIÇOS E TAXAS', 'ai_order' => 7, 'title_field' => 'name', 'badge_field' => 'price',
        ])->assertRedirect();

        $template = BrainTemplate::where('slug', 'imobiliaria')->first();
        $s = $template->structure;
        foreach ($s['tabs'] as &$t) {
            if ($t['key'] === 'servicos') {
                $t['label'] = 'Serviços (modelo)';
            }
        }
        $template->update(['structure' => $s, 'version' => 2]);

        $change = collect(TemplateApplier::changes($this->a->fresh()))->firstWhere('id', 'tab:servicos:label');
        $this->assertFalse($change['checked']);
        $this->assertNotNull($change['warning']);

        $before = $this->structureOf($this->a);
        $this->actingAs($this->admin)->post("/admin/clientes/{$this->a->id}/atualizacao/dispensar")->assertRedirect();
        $this->assertEquals($before, $this->structureOf($this->a));
        $this->assertFalse($this->a->fresh()->hasPendingUpdate());
    }

    // 12.5 — núcleo travado.
    public function test_nucleo_nao_pode_ser_arquivado_nem_trocar_de_formato(): void
    {
        $base = "/admin/clientes/{$this->a->id}/estrutura";
        $this->enterAs($this->a)->patch("$base/abas/regras/arquivar")->assertSessionHasErrors('structure');
        $this->enterAs($this->a)->put("$base/abas/faq", ['label' => 'FAQ', 'ai_format' => 'lista', 'ai_order' => 10, 'title_field' => 'question'])
            ->assertSessionHasErrors('structure');
        $this->enterAs($this->a)->patch("$base/abas/personalidade/campos/bot_name/arquivar")->assertSessionHasErrors('structure');
    }

    // 12.6 — chave arquivada fica ocupada; renomear não muda a chave.
    public function test_campo_novo_com_nome_de_arquivado_ganha_sufixo(): void
    {
        $base = "/admin/clientes/{$this->a->id}/estrutura";
        $this->enterAs($this->a)->post("$base/abas/servicos/campos", ['label' => 'Prazo', 'type' => 'text'])->assertRedirect();
        $this->enterAs($this->a)->patch("$base/abas/servicos/campos/prazo/arquivar")->assertRedirect();
        $this->enterAs($this->a)->post("$base/abas/servicos/campos", ['label' => 'Prazo', 'type' => 'money'])->assertRedirect();
        $this->enterAs($this->a)->put("$base/abas/servicos/campos/prazo_2", ['label' => 'Prazo novo', 'type' => 'money'])->assertRedirect();

        $keys = array_column(collect($this->structureOf($this->a)['tabs'])->firstWhere('key', 'servicos')['fields'], 'key');
        $this->assertContains('prazo', $keys);
        $this->assertContains('prazo_2', $keys);
    }

    // 12.7 — arquivar campo mantém o valor; editar o item não apaga.
    public function test_arquivar_campo_mantem_o_valor(): void
    {
        app(CurrentTenant::class)->set($this->a);
        $item = BrainItem::create(['section' => 'servicos', 'data' => ['name' => 'Taxa', 'conditions' => 'Só à vista'], 'position' => 1, 'active' => true]);
        app(CurrentTenant::class)->set(null);

        $base = "/admin/clientes/{$this->a->id}/estrutura";
        $this->enterAs($this->a)->patch("$base/abas/servicos/campos/conditions/arquivar")->assertSessionHasErrors('structure'); // usado pelo formato

        // Fora do formato específico, dá para arquivar.
        $this->enterAs($this->a)->put("$base/abas/servicos", ['label' => 'Serviços e taxas', 'ai_format' => 'lista', 'ai_order' => 7, 'title_field' => 'name'])->assertRedirect();
        $this->enterAs($this->a)->patch("$base/abas/servicos/campos/conditions/arquivar")->assertRedirect();

        $this->actingAs($this->ownerA)->put("/cerebro/servicos/itens/{$item->id}", ['fields' => ['name' => 'Taxa nova']])->assertRedirect();
        $this->assertSame('Só à vista', $item->fresh()->data['conditions']);
    }

    // 12.8 — dinheiro.
    public function test_dinheiro_guarda_centavos(): void
    {
        $this->assertSame(123456, FieldTypes::parseMoney('1.234,56'));
        $this->assertSame(1250, FieldTypes::parseMoney('12.50'));
        $this->assertSame(123400, FieldTypes::parseMoney('1.234'));
        $this->assertSame('R$ 1.234,56', FieldTypes::formatMoney(123456));

        $user = User::create(['tenant_id' => $this->b->id, 'name' => 'Dono B', 'email' => 'b@x.com', 'password' => 'secret123', 'role' => 'dono']);
        $this->actingAs($user)->post('/cerebro/cursos/itens', ['fields' => ['nome' => 'Enfermagem', 'mensalidade' => '1.234,56']])->assertRedirect();
        $item = BrainItem::withoutGlobalScopes()->where('tenant_id', $this->b->id)->where('section', 'cursos')->first();
        $this->assertSame(123456, $item->data['mensalidade']);

        $this->actingAs($user)->from('/cerebro/cursos')->post('/cerebro/cursos/itens', ['fields' => ['nome' => 'X', 'mensalidade' => '1,234.56']])
            ->assertSessionHasErrors('fields.mensalidade');
    }

    // 12.9 — imagem.
    public function test_imagem_grava_na_pasta_do_cliente_e_nao_entra_no_prompt(): void
    {
        Storage::fake('public');
        $user = User::create(['tenant_id' => $this->b->id, 'name' => 'Dono B', 'email' => 'b@x.com', 'password' => 'secret123', 'role' => 'dono']);

        $this->actingAs($user)->post('/cerebro/cursos/itens', ['fields' => [
            'nome' => 'Radiologia',
            // PNG de 1x1 real (não depende da extensão GD).
            'foto' => UploadedFile::fake()->createWithContent('sala.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')),
        ]])->assertRedirect();

        $item = BrainItem::withoutGlobalScopes()->where('tenant_id', $this->b->id)->where('section', 'cursos')->first();
        $path = $item->data['foto'];
        $this->assertStringStartsWith("tenants/{$this->b->id}/", $path);
        Storage::disk('public')->assertExists($path);

        // Editar sem arquivo mantém a imagem.
        $this->actingAs($user)->put("/cerebro/cursos/itens/{$item->id}", ['fields' => ['nome' => 'Radiologia 2']])->assertRedirect();
        $this->assertSame($path, $item->fresh()->data['foto']);

        $prompt = BrainVersion::withoutGlobalScopes()->where('tenant_id', $this->b->id)->latest('version')->first()->prompt;
        $this->assertStringContainsString('Radiologia 2', $prompt);
        $this->assertStringNotContainsString($path, $prompt);

        // SVG e PDF recusados.
        $this->actingAs($user)->from('/cerebro/cursos')->post('/cerebro/cursos/itens', ['fields' => [
            'nome' => 'X', 'foto' => UploadedFile::fake()->create('a.svg', 10, 'image/svg+xml'),
        ]])->assertSessionHasErrors('fields.foto');
    }

    // 12.12 — dono não entra em nada de estrutura.
    public function test_dono_nao_acessa_estrutura_modelos_nem_atualizacao(): void
    {
        $template = BrainTemplate::first();
        $this->actingAs($this->ownerA)->get('/admin/modelos')->assertForbidden();
        $this->actingAs($this->ownerA)->get("/admin/modelos/{$template->id}/estrutura")->assertForbidden();
        $this->actingAs($this->ownerA)->get("/admin/clientes/{$this->a->id}/estrutura")->assertForbidden();
        $this->actingAs($this->ownerA)->get("/admin/clientes/{$this->a->id}/atualizacao")->assertForbidden();
        $this->actingAs($this->ownerA)->patch("/admin/clientes/{$this->a->id}/modulos", ['modules' => ['agenda' => 1]])->assertForbidden();
    }

    // Admin editando A nunca mexe em B.
    public function test_editar_estrutura_de_um_cliente_nao_mexe_no_outro(): void
    {
        $before = $this->structureOf($this->b);
        $this->enterAs($this->a)->post("/admin/clientes/{$this->a->id}/estrutura/abas", ['label' => 'Bairros', 'type' => 'items'])->assertRedirect();
        $this->assertEquals($before, $this->structureOf($this->b));
        $this->assertContains('bairros', array_column($this->structureOf($this->a)['tabs'], 'key'));
    }

    // Itens iniciais do modelo Escola técnica.
    public function test_escola_tecnica_nasce_com_regras_iniciais(): void
    {
        $rules = BrainItem::withoutGlobalScopes()->where('tenant_id', $this->b->id)->where('section', 'regras')->get();
        $this->assertCount(2, $rules);
        $this->assertStringContainsString('você vai ganhar', $rules->first()->data['rule']);
    }

    // 12.11 — restaurar volta o conteúdo e mantém a estrutura atual.
    public function test_restaurar_mantem_a_estrutura_atual(): void
    {
        $this->actingAs($this->ownerA)->post('/cerebro/faq/itens', ['fields' => ['question' => 'P1', 'answer' => 'R1']]);
        $this->enterAs($this->a)->post("/admin/clientes/{$this->a->id}/estrutura/abas", ['label' => 'Bairros', 'type' => 'items']);
        $this->actingAs($this->ownerA)->post('/cerebro/versoes/1/restaurar')->assertRedirect();

        $this->assertContains('bairros', array_column($this->structureOf($this->a)['tabs'], 'key'));
    }

    // 13 — módulos na API.
    public function test_api_traz_modulos(): void
    {
        $this->actingAs($this->admin)->patch("/admin/clientes/{$this->a->id}/modulos", ['modules' => ['catalogo' => 1, 'agenda' => 0]]);
        $token = $this->a->fresh()->generateApiToken();
        $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}"])
            ->assertJsonPath('modules.catalogo', true)
            ->assertJsonPath('modules.agenda', false)
            ->assertJsonStructure(['tenant', 'active', 'version', 'updated_at', 'bot_name', 'greeting', 'max_bot_replies', 'handoff_message', 'prompt', 'settings', 'sections']);
    }
}
