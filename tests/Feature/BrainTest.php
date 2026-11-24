<?php

namespace Tests\Feature;

use App\Brain\TemplateApplier;
use App\Brain\Versioner;
use App\Models\BrainItem;
use App\Models\BrainVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Tenant::create(['name' => 'Imob A', 'slug' => 'imob-a', 'status' => 'ativo']);
        $this->b = Tenant::create(['name' => 'Imob B', 'slug' => 'imob-b', 'status' => 'ativo']);
        $this->userA = User::create(['tenant_id' => $this->a->id, 'name' => 'A', 'email' => 'a@x.com', 'password' => 'secret123', 'role' => 'dono']);
        $this->userB = User::create(['tenant_id' => $this->b->id, 'name' => 'B', 'email' => 'b@x.com', 'password' => 'secret123', 'role' => 'dono']);

        // Mesmo caminho do cadastro de cliente: estrutura copiada do modelo Imobiliária.
        TemplateApplier::provision($this->a, 'imobiliaria');
        TemplateApplier::provision($this->b, 'imobiliaria');

        BrainItem::create(['tenant_id' => $this->a->id, 'section' => 'faq', 'data' => ['question' => 'Pergunta da A', 'answer' => 'Resposta A'], 'position' => 1, 'active' => true]);
        BrainItem::create(['tenant_id' => $this->b->id, 'section' => 'faq', 'data' => ['question' => 'Pergunta da B', 'answer' => 'Resposta B'], 'position' => 1, 'active' => true]);

        app(CurrentTenant::class)->set(null);
    }

    public function test_cliente_so_ve_o_proprio_cerebro(): void
    {
        $this->actingAs($this->userA)->get('/cerebro/faq')
            ->assertOk()
            ->assertSee('Pergunta da A')
            ->assertDontSee('Pergunta da B');
    }

    public function test_cliente_nao_edita_nem_exclui_item_de_outro(): void
    {
        $itemB = BrainItem::withoutGlobalScopes()->where('tenant_id', $this->b->id)->first();

        $this->actingAs($this->userA)
            ->put("/cerebro/faq/itens/{$itemB->id}", ['fields' => ['question' => 'hack', 'answer' => 'hack']])
            ->assertNotFound();

        $this->actingAs($this->userA)->delete("/cerebro/faq/itens/{$itemB->id}")->assertNotFound();

        $this->assertSame('Pergunta da B', $itemB->fresh()->data['question']);
    }

    public function test_adicionar_item_grava_no_cliente_certo_e_cria_versao(): void
    {
        $this->actingAs($this->userA)
            ->post('/cerebro/regras/itens', ['fields' => ['kind' => 'nunca', 'rule' => 'Nunca dar desconto.']])
            ->assertRedirect('/cerebro/regras');

        $item = BrainItem::withoutGlobalScopes()->where('section', 'regras')->first();
        $this->assertSame($this->a->id, $item->tenant_id);

        $version = BrainVersion::withoutGlobalScopes()->where('tenant_id', $this->a->id)->latest('version')->first();
        $this->assertSame(1, $version->version);
        $this->assertStringContainsString('Nunca dar desconto.', $version->prompt);
    }

    public function test_validacao_de_campo_obrigatorio(): void
    {
        $this->actingAs($this->userA)
            ->from('/cerebro/faq')
            ->post('/cerebro/faq/itens', ['fields' => ['question' => '', 'answer' => '']])
            ->assertSessionHasErrors(['fields.question', 'fields.answer']);
    }

    public function test_salvar_aba_de_formulario(): void
    {
        $this->actingAs($this->userA)
            ->put('/cerebro/personalidade', ['fields' => ['bot_name' => 'Carla', 'tone_of_voice' => 'Simpático', 'emojis' => 'nunca']])
            ->assertRedirect('/cerebro/personalidade');

        $this->actingAs($this->userA)->get('/cerebro/personalidade')->assertSee('Carla');
        $this->actingAs($this->userB)->get('/cerebro/personalidade')->assertDontSee('Carla');
    }

    public function test_api_devolve_o_cerebro_do_dono_do_token(): void
    {
        $token = $this->a->generateApiToken();

        $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'imob-a')
            ->assertJsonPath('active', true)
            ->assertJsonPath('sections.faq.0.question', 'Pergunta da A')
            ->assertJsonMissing(['question' => 'Pergunta da B']);
    }

    public function test_api_recusa_token_invalido(): void
    {
        $this->getJson('/api/v1/brain', ['Authorization' => 'Bearer errado'])->assertUnauthorized();
        $this->getJson('/api/v1/brain')->assertUnauthorized();
    }

    public function test_api_avisa_quando_cliente_esta_suspenso(): void
    {
        $token = $this->a->generateApiToken();
        $this->a->update(['status' => 'suspenso']);

        $this->getJson('/api/v1/brain', ['Authorization' => "Bearer {$token}"])->assertJsonPath('active', false);
    }

    public function test_restaurar_versao_antiga(): void
    {
        $this->actingAs($this->userA)->post('/cerebro/faq/itens', ['fields' => ['question' => 'Nova', 'answer' => 'X']]);
        $this->actingAs($this->userA)->delete('/cerebro/faq/itens/'.BrainItem::withoutGlobalScopes()->where('tenant_id', $this->a->id)->where('section', 'faq')->orderByDesc('id')->value('id'));

        // v1 tinha "Nova"; v2 excluiu. Restaurar v1:
        $this->actingAs($this->userA)->post('/cerebro/versoes/1/restaurar')->assertRedirect('/cerebro/versoes');

        $questions = BrainItem::withoutGlobalScopes()->where('tenant_id', $this->a->id)->get()->pluck('data.question')->all();
        $this->assertContains('Nova', $questions);
        $this->assertSame(3, (int) BrainVersion::withoutGlobalScopes()->where('tenant_id', $this->a->id)->max('version'));

        // Nada da B foi mexido.
        $this->assertSame(1, BrainItem::withoutGlobalScopes()->where('tenant_id', $this->b->id)->count());
    }

    public function test_cliente_nao_entra_no_admin(): void
    {
        $this->actingAs($this->userA)->get('/admin/clientes')->assertForbidden();
    }
}
