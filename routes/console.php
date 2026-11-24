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
