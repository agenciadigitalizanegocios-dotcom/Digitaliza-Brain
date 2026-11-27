<?php

namespace Tests\Feature;

use App\Brain\TemplateApplier;
use App\Catalog\ProductImporter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Parte 2: catálogo de produtos, vitrine pública e busca do robô. */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Tenant $a;

    private Tenant $b;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->a = Tenant::create(['name' => 'Imob A', 'slug' => 'imob-a', 'status' => 'ativo']);
        $this->b = Tenant::create(['name' => 'Imob B', 'slug' => 'imob-b', 'status' => 'ativo']);
        TemplateApplier::provision($this->a, 'imobiliaria');
        TemplateApplier::provision($this->b, 'imobiliaria');
        $this->a->update(['modules' => ['catalogo' => true, 'agenda' => false]]);
        $this->b->update(['modules' => ['catalogo' => true, 'agenda' => false]]);

        $this->owner = User::create(['tenant_id' => $this->a->id, 'name' => 'Dono', 'email' => 'dono@x.com', 'password' => 'secret123', 'role' => 'dono']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'secret123', 'role' => 'admin']);
        app(CurrentTenant::class)->set(null);
    }

    private function imovel(array $fields, array $extra = []): array
    {
        return array_merge([
            'status' => 'disponivel', 'published' => 1, 'featured' => 0,
            'fields' => array_merge(['titulo' => 'Casa no Centro', 'tipo' => 'casa', 'aluguel' => '1.500,00', 'bairro' => 'Centro'], $fields),
        ], $extra);
    }

    private function create(array $fields = [], array $extra = []): Product
    {
        $this->actingAs($this->owner)->post('/cerebro/produtos/novo/imovel', $this->imovel($fields, $extra))->assertRedirect();

        return Product::withoutGlobalScopes()->where('tenant_id', $this->a->id)->latest('id')->first();
    }

    public function test_cliente_novo_do_modelo_imobiliaria_ganha_o_tipo_imovel(): void
    {
        $type = ProductType::withoutGlobalScopes()->where('tenant_id', $this->a->id)->first();
        $this->assertSame('imovel', $type->key);
        $this->assertSame('aluguel', $type->price_field);
    }

    public function test_cadastrar_produto_com_fotos_e_dinheiro(): void
    {
        $this->actingAs($this->owner)->post('/cerebro/produtos/novo/imovel', $this->imovel(['quartos' => 2, 'endereco' => 'Rua Secreta, 10'], [
            'photos' => [
                UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG)),
                UploadedFile::fake()->createWithContent('b.png', base64_decode(self::PNG)),
            ],
        ]))->assertRedirect();

        $p = Product::withoutGlobalScopes()->where('tenant_id', $this->a->id)->first();
        $this->assertSame(150000, $p->data['aluguel']);
        $this->assertSame(2, $p->images()->count());
        $this->assertStringStartsWith("tenants/{$this->a->id}/", $p->images()->first()->path);
    }

    public function test_vitrine_mostra_so_publicados_e_disponiveis_e_esconde_campo_interno(): void
    {
        $ok = $this->create(['titulo' => 'Casa Bonita', 'endereco' => 'Rua Secreta, 10']);
        $this->create(['titulo' => 'Casa Rascunho'], ['published' => 0]);
        $this->create(['titulo' => 'Casa Alugada'], ['status' => 'indisponivel']);

        $this->get('/v/imob-a')->assertOk()
            ->assertSee('Casa Bonita')->assertSee('R$ 1.500,00')
            ->assertDontSee('Casa Rascunho')->assertDontSee('Casa Alugada');

        $this->get('/v/imob-a/'.$ok->urlSlug())->assertOk()->assertSee('Casa Bonita')->assertDontSee('Rua Secreta');
        $this->get('/v/imob-a/qualquer-coisa-'.$ok->id)->assertRedirect('/v/imob-a/'.$ok->urlSlug());
    }

    public function test_filtros_da_vitrine(): void
    {
        $this->create(['titulo' => 'Casa Centro barata', 'bairro' => 'Centro', 'aluguel' => '1.200,00', 'quartos' => 2]);
        $this->create(['titulo' => 'Apto Parque caro', 'bairro' => 'Parque', 'aluguel' => '2.500,00', 'quartos' => 3, 'tipo' => 'apartamento']);

        $this->get('/v/imob-a?bairro=Centro')->assertSee('Casa Centro barata')->assertDontSee('Apto Parque caro');
        $this->get('/v/imob-a?aluguel_max=1500')->assertSee('Casa Centro barata')->assertDontSee('Apto Parque caro');
        $this->get('/v/imob-a?quartos_min=3')->assertSee('Apto Parque caro')->assertDontSee('Casa Centro barata');
        $this->get('/v/imob-a?tipo=apartamento')->assertSee('Apto Parque caro')->assertDontSee('Casa Centro barata');
    }

    public function test_vitrine_some_sem_o_modulo_ou_com_cliente_suspenso(): void
    {
        $this->a->update(['modules' => ['catalogo' => false, 'agenda' => false]]);
        $this->get('/v/imob-a')->assertNotFound();
        $this->actingAs($this->owner)->get('/cerebro/produtos')->assertNotFound();

        $this->b->update(['status' => 'suspenso']);
        $this->get('/v/imob-b')->assertNotFound();
    }

    public function test_isolamento_entre_clientes(): void
    {
        app(CurrentTenant::class)->set($this->b);
        $typeB = ProductType::where('key', 'imovel')->first();
        $pB = Product::create(['product_type_id' => $typeB->id, 'data' => ['titulo' => 'Casa da B', 'aluguel' => 100000, 'bairro' => 'X', 'tipo' => 'casa']]);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->owner)->get("/cerebro/produtos/{$pB->id}")->assertNotFound();
        $this->actingAs($this->owner)->delete("/cerebro/produtos/{$pB->id}")->assertNotFound();
        $this->get('/v/imob-a/casa-da-b-'.$pB->id)->assertNotFound();

        $token = $this->a->generateApiToken();
        $this->getJson("/api/v1/products/{$pB->id}", ['Authorization' => "Bearer {$token}"])->assertNotFound();
        $this->assertTrue(Product::withoutGlobalScopes()->whereKey($pB->id)->exists());
    }

    public function test_api_de_busca_do_robo(): void
    {
        $this->create(['titulo' => 'Casa Centro', 'bairro' => 'Centro', 'aluguel' => '1.200,00', 'endereco' => 'Rua Secreta, 10', 'aceita_pet' => 1]);
        $this->create(['titulo' => 'Apto Parque', 'bairro' => 'Parque', 'aluguel' => '2.500,00']);
        $token = $this->a->generateApiToken();
        $h = ['Authorization' => "Bearer {$token}"];

        $json = $this->getJson('/api/v1/products?bairro=centro&aluguel_max=1500&aceita_pet=1', $h)->assertOk()->json();
        $this->assertSame(1, $json['total']);
        $item = $json['items'][0];
        $this->assertSame('Casa Centro', $item['title']);
        $this->assertSame(120000, $item['price']);
        $this->assertSame('R$ 1.200,00/mês', $item['price_text']);
        $this->assertArrayNotHasKey('endereco', $item['fields']);
        $this->assertStringContainsString('/v/imob-a/casa-centro-', $item['url']);

        $this->getJson('/api/v1/products/types', $h)->assertOk()->assertJsonPath('types.0.key', 'imovel');
        $this->getJson('/api/v1/products', $h + ['Cf-Connecting-Ip' => '1.2.3.4'])->assertNotFound();
    }

    public function test_corretor_so_ve_produtos(): void
    {
        $agent = User::create(['tenant_id' => $this->a->id, 'name' => 'Corretor', 'email' => 'c@x.com', 'password' => 'secret123', 'role' => 'corretor']);
        $this->actingAs($agent)->get('/cerebro/produtos')->assertOk();
        $this->actingAs($agent)->post('/cerebro/produtos/novo/imovel', $this->imovel([]))->assertForbidden();
    }

    public function test_admin_monta_tipo_de_produto(): void
    {
        $base = "/admin/clientes/{$this->a->id}/catalogo";
        $this->actingAs($this->admin)->post("$base/tipos", ['name' => 'Sala comercial'])->assertRedirect();
        $this->actingAs($this->admin)->post("$base/tipos/sala_comercial/campos", ['label' => 'Valor', 'type' => 'money', 'send_to_ai' => 1])->assertRedirect();
        $this->actingAs($this->admin)->put("$base/tipos/sala_comercial", [
            'name' => 'Sala comercial', 'title_field' => 'titulo', 'price_field' => 'valor', 'filters' => ['valor'],
            'status_labels' => ['disponivel' => 'Livre', 'reservado' => 'Reservada', 'indisponivel' => 'Ocupada'],
        ])->assertRedirect();

        app(CurrentTenant::class)->set($this->a);
        $t = ProductType::where('key', 'sala_comercial')->first();
        $this->assertSame('valor', $t->price_field);
        $this->assertSame('Ocupada', $t->statusLabel('indisponivel'));

        // Preço precisa ser dinheiro.
        $this->actingAs($this->admin)->put("$base/tipos/sala_comercial", [
            'name' => 'Sala comercial', 'title_field' => 'titulo', 'price_field' => 'titulo',
            'status_labels' => ['disponivel' => 'Livre', 'reservado' => 'Reservada', 'indisponivel' => 'Ocupada'],
        ])->assertSessionHasErrors('structure');

        $this->actingAs($this->owner)->get($base)->assertForbidden();

        // Chave reservada vira outra; campo de imagem novo não entra; nome repetido não entra.
        $this->actingAs($this->admin)->post("$base/tipos/sala_comercial/campos", ['label' => 'Produto', 'type' => 'text', 'send_to_ai' => 1])->assertRedirect();
        $this->actingAs($this->admin)->post("$base/tipos/sala_comercial/campos", ['label' => 'Área max', 'type' => 'number', 'send_to_ai' => 1])->assertRedirect();
        $this->actingAs($this->admin)->post("$base/tipos/sala_comercial/campos", ['label' => 'Foto extra', 'type' => 'image'])->assertSessionHasErrors('structure');
        $this->actingAs($this->admin)->post("$base/tipos/sala_comercial/campos", ['label' => 'valor', 'type' => 'text'])->assertSessionHasErrors('structure');
        app(CurrentTenant::class)->set($this->a);
        $keys = array_column(ProductType::where('key', 'sala_comercial')->first()->fields, 'key');
        $this->assertNotContains('produto', $keys);
        $this->assertNotContains('area_max', $keys);
        foreach ($keys as $k) {
            $this->assertFalse(\App\Catalog\ProductTypeValidator::isReservedKey($k), $k);
        }
    }

    public function test_importador_le_a_api_do_site_antigo(): void
    {
        Http::fake([
            'imoveis.test/api/v1/imoveis' => Http::response(['data' => [
                ['id' => 7, 'titulo' => 'Casa importada', 'tipo' => 'Casa', 'aluguel' => '1800.00', 'bairro' => 'Centro',
                    'quartos' => '2', 'aceita_pet' => 1, 'mostrar_no_site' => true, 'fotos' => ['/storage/a.png']],
                ['id' => 8, 'titulo' => '', 'aluguel' => 100],
            ]]),
            'imoveis.test/storage/a.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
        ]);

        app(CurrentTenant::class)->set($this->a);
        $type = ProductType::where('key', 'imovel')->first();
        $importer = new ProductImporter($this->a, $type, 'http://imoveis.test/api/v1/imoveis');
        $report = $importer->import($importer->fetch());

        $this->assertSame(1, $report['criados']);
        $this->assertSame(1, $report['pulados']);
        $p = Product::where('external_id', '7')->first();
        $this->assertSame(180000, $p->data['aluguel']);
        $this->assertSame('casa', $p->data['tipo']);
        $this->assertTrue($p->data['aceita_pet']);
        $this->assertSame(1, $p->images()->count());

        // Rodar de novo não duplica.
        $again = $importer->import($importer->fetch());
        $this->assertSame(1, $again['atualizados']);
        $this->assertSame(1, Product::where('external_id', '7')->count());
    }

    public function test_campo_interno_nao_vira_filtro_titulo_nem_cartao(): void
    {
        app(CurrentTenant::class)->set($this->a);
        $def = ProductType::where('key', 'imovel')->first()->definition();

        $withFilter = $def;
        $withFilter['filters'][] = 'endereco';
        $this->assertNotEmpty(\App\Catalog\ProductTypeValidator::validate($withFilter, $def));

        $withTitle = $def;
        $withTitle['title_field'] = 'endereco';
        $this->assertNotEmpty(\App\Catalog\ProductTypeValidator::validate($withTitle, $def));

        $withCard = $def;
        $withCard['card_fields'] = ['endereco'];
        $this->assertNotEmpty(\App\Catalog\ProductTypeValidator::validate($withCard, $def));

        $tooManyFilters = $def;
        $tooManyFilters['filters'] = ['tipo', 'bairro', 'aluguel', 'quartos', 'aceita_pet', 'vagas', 'banheiros'];
        $this->assertNotEmpty(\App\Catalog\ProductTypeValidator::validate($tooManyFilters, $def));

        $this->assertSame([], \App\Catalog\ProductTypeValidator::validate($def, $def));
    }

    public function test_importador_nao_usa_endereco_como_titulo_nem_segue_outro_site(): void
    {
        Http::fake([
            'imoveis.test/api/v1/imoveis' => Http::response(['data' => [
                ['id' => 9, 'endereco' => 'Rua Secreta, 10', 'aluguel' => '900'],
            ], 'next_page_url' => 'http://outro-site.test/roubar']),
            'outro-site.test/*' => Http::response(['data' => []]),
        ]);

        app(CurrentTenant::class)->set($this->a);
        $type = ProductType::where('key', 'imovel')->first();
        $importer = new ProductImporter($this->a, $type, 'http://imoveis.test/api/v1/imoveis', 'token-secreto');
        $report = $importer->import($importer->fetch());

        $this->assertSame(0, $report['criados']);
        $this->assertSame(1, $report['pulados']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'outro-site.test'));
    }

    public function test_tipo_de_produto_vai_em_produto_e_vitrine_nova_fica_fora_do_google(): void
    {
        $this->create(['titulo' => 'Casa Centro', 'tipo' => 'casa']);
        $this->create(['titulo' => 'Apto Centro', 'tipo' => 'apartamento']);

        $res = $this->get('/v/imob-a?produto=imovel&tipo=casa')->assertOk()->assertSee('Casa Centro')->assertDontSee('Apto Centro');
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->a->update(['vitrine' => array_merge($this->a->vitrineSettings(), ['building' => false])]);
        $this->get('/v/imob-a')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }
}
