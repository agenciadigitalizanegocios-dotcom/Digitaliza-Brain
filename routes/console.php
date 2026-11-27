<?php

use App\Brain\PromptBuilder;
use App\Brain\Structure;
use App\Brain\Templates;
use App\Brain\Versioner;
use App\Http\Controllers\Api\BrainApiController;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\BrainVersion;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Aponta a conexão sqlite para outro arquivo só durante o comando (funciona igual no Windows e no Linux). */
$useDatabase = function (?string $file) {
    if (! $file) {
        return;
    }
    $path = realpath($file) ?: base_path($file);
    if (! is_file($path)) {
        throw new RuntimeException("Arquivo de banco não encontrado: {$file}");
    }
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path]);
    DB::purge('sqlite');
};

/*
 | Foto do cérebro de cada cliente: a resposta da API (sem updated_at) e o prompt remontado
 | a partir do conteúdo atual. Serve para provar que a migração não mudou nada.
 |   php artisan cerebro:foto storage/app/foto-antes.json --banco=database/data/prod-copia.sqlite
 */
Artisan::command('cerebro:foto {arquivo} {--banco=}', function (string $arquivo) use ($useDatabase) {
    $useDatabase($this->option('banco'));
    $out = [];

    foreach (Tenant::orderBy('id')->get() as $tenant) {
        app(CurrentTenant::class)->set($tenant);
        Structure::forget();
        Cache::forget(Versioner::cacheKey($tenant->id));

        $settings = BrainSetting::current();
        $structure = (\Illuminate\Support\Facades\Schema::hasColumn('brain_settings', 'structure') ? $settings->structure : null) ?? Templates::fallback();
        $items = BrainItem::orderBy('section')->orderBy('position')->get()->all();

        $api = json_decode(app(BrainApiController::class)()->getContent(), true);
        unset($api['updated_at']);

        $out[$tenant->slug] = [
            'api' => $api,
            'prompt_remontado' => PromptBuilder::build($settings->data ?? [], $items, $structure),
        ];
        Cache::forget(Versioner::cacheKey($tenant->id));
    }

    $path = str_starts_with($arquivo, '/') || preg_match('/^[A-Za-z]:/', $arquivo) ? $arquivo : base_path($arquivo);
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $this->info('Foto de '.count($out).' cliente(s) gravada em '.$path);
})->purpose('Grava a API e o prompt de cada cliente num JSON, para comparar antes e depois');

/*
 | Roda as migrations numa cópia do banco, sem mexer no .env.
 |   php artisan cerebro:migrar-copia --banco=database/data/prod-copia.sqlite
 */
Artisan::command('cerebro:migrar-copia {--banco=}', function () use ($useDatabase) {
    if (! $this->option('banco')) {
        $this->error('Informe --banco=caminho/da/copia.sqlite');

        return 1;
    }
    $useDatabase($this->option('banco'));
    $this->call('migrate', ['--force' => true]);
})->purpose('Roda as migrations numa cópia do banco SQLite');

/*
 | Imagens que não são usadas pelo conteúdo atual nem por nenhuma versão guardada.
 | Sem --apagar, só lista.
 */
Artisan::command('cerebro:limpar-imagens {--apagar}', function () {
    $used = [];
    $collect = function ($value) use (&$used, &$collect) {
        if (is_array($value)) {
            array_walk($value, $collect);
        } elseif (is_string($value) && str_starts_with($value, 'tenants/')) {
            $used[$value] = true;
        }
    };

    BrainSetting::withoutGlobalScopes()->each(fn ($s) => $collect($s->data ?? []));
    BrainItem::withoutGlobalScopes()->each(fn ($i) => $collect($i->data ?? []));
    BrainVersion::withoutGlobalScopes()->each(fn ($v) => $collect([$v->snapshot['settings'] ?? [], $v->snapshot['items'] ?? []]));
    // Catálogo: fotos da galeria, campos de imagem dos produtos e logo da vitrine.
    \App\Models\ProductImage::withoutGlobalScopes()->pluck('path')->each(fn ($p) => $collect($p));
    \App\Models\Product::withoutGlobalScopes()->each(fn ($p) => $collect($p->data ?? []));
    Tenant::query()->each(fn ($t) => $collect($t->vitrine ?? []));

    $disk = Storage::disk('public');
    $unused = array_values(array_filter($disk->allFiles('tenants'), fn ($f) => ! isset($used[$f])));

    if (! $unused) {
        $this->info('Nenhuma imagem sem uso.');

        return;
    }

    foreach ($unused as $file) {
        $this->line(($this->option('apagar') ? 'apagando  ' : 'sem uso   ').$file);
        if ($this->option('apagar')) {
            $disk->delete($file);
        }
    }
    $this->info(count($unused).' imagem(ns) '.($this->option('apagar') ? 'apagada(s).' : 'sem uso. Rode com --apagar para apagar.'));
})->purpose('Lista (ou apaga com --apagar) imagens que nenhuma versão usa');

/*
 | Importa produtos de uma API JSON (ex.: o site de imóveis atual).
 |   php artisan cerebro:importar bs-imoveis http://imoveis:3000/api/v1/imoveis --token=XXX --dry-run
 | Sem --dry-run, grava. Rodar de novo atualiza pelo id de origem (não duplica).
 | --mapa=caminho.json troca os nomes dos campos (veja App\Catalog\ProductImporter::DEFAULT_MAP).
 */
Artisan::command('cerebro:importar {cliente} {url} {--token=} {--tipo=imovel} {--mapa=} {--dry-run} {--fotos-de-novo}', function (string $cliente, string $url) {
    $tenant = Tenant::where('slug', $cliente)->first();
    if (! $tenant) {
        $this->error("Cliente “{$cliente}” não encontrado (use o slug, ex.: bs-imoveis).");

        return 1;
    }
    app(CurrentTenant::class)->set($tenant);
    $type = \App\Models\ProductType::where('key', $this->option('tipo'))->first();
    if (! $type) {
        $this->error("O cliente não tem o tipo de produto “{$this->option('tipo')}”.");

        return 1;
    }

    $map = [];
    if ($this->option('mapa')) {
        $map = json_decode((string) file_get_contents($this->option('mapa')), true);
        if (! is_array($map)) {
            $this->error('Mapa inválido (precisa ser JSON).');

            return 1;
        }
    }

    $importer = new \App\Catalog\ProductImporter($tenant, $type, $url, $this->option('token'), $map);
    $items = $importer->fetch();
    $this->info(count($items).' item(ns) encontrados na API.');

    if ($this->option('dry-run')) {
        if ($items) {
            $this->line('Campos do 1º item na API: '.implode(', ', array_keys((array) $items[0])));
        }
        foreach (array_slice($items, 0, 3) as $i => $item) {
            $this->line("\n— Item ".($i + 1).' convertido:');
            $this->line(json_encode($importer->mapItem((array) $item), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->warn("\nNada foi gravado (--dry-run). Confira e rode sem --dry-run.");

        return 0;
    }

    $report = $importer->import($items, (bool) $this->option('fotos-de-novo'));
    $this->info("Criados: {$report['criados']} · Atualizados: {$report['atualizados']} · Pulados: {$report['pulados']} · Fotos: {$report['fotos']}");
    foreach (array_slice($report['avisos'], 0, 30) as $aviso) {
        $this->warn($aviso);
    }
})->purpose('Importa produtos de uma API JSON para o catálogo de um cliente');

/*
 | Troca a senha de um usuário. A senha é digitada às cegas no terminal e nunca aparece
 | em tela, log ou auditoria. Também derruba o "lembrar de mim" dele.
 |   php artisan cerebro:senha              (lista os usuários e pergunta qual)
 |   php artisan cerebro:senha bs@digitaliza.com.br
 */
Artisan::command('cerebro:senha {email?}', function (?string $email = null) {
    $users = \App\Models\User::orderBy('role')->orderBy('email')->get(['id', 'name', 'email', 'role']);
    if ($users->isEmpty()) {
        $this->error('Nenhum usuário cadastrado. Rode php artisan migrate --seed antes.');

        return 1;
    }

    if (! $email) {
        $email = $this->choice('Trocar a senha de quem?', $users->map(fn ($u) => $u->email)->all());
    }

    $user = \App\Models\User::where('email', $email)->first();
    if (! $user) {
        $this->error("Usuário não encontrado: {$email}");

        return 1;
    }

    $senha = (string) $this->secret('Senha nova (mínimo 10 caracteres; não aparece enquanto digita)');
    if (mb_strlen($senha) < 10) {
        $this->error('Senha curta. Use pelo menos 10 caracteres.');

        return 1;
    }
    if ($senha !== (string) $this->secret('Repita a senha')) {
        $this->error('As senhas não batem. Nada foi alterado.');

        return 1;
    }

    $user->forceFill([
        'password' => $senha,
        'remember_token' => \Illuminate\Support\Str::random(60),
    ])->save();

    \App\Support\Audit::log('senha_trocada', $user->tenant_id, ['usuario' => $user->email]);
    $this->info("Senha de {$user->email} trocada. O \"lembrar de mim\" antigo parou de funcionar.");

    return 0;
})->purpose('Troca a senha de um usuário sem mostrar a senha');
