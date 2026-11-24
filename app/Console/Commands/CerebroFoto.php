<?php

namespace App\Console\Commands;

use App\Brain\PromptBuilder;
use App\Http\Controllers\Api\BrainApiController;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tira uma "foto" do cérebro de todos os clientes: a resposta da API (sem updated_at)
 * e o prompt remontado a partir do conteúdo atual. Serve para comparar antes e depois
 * de uma mudança no código ou de uma migração.
 *
 * Não grava nada no banco: cada cliente roda dentro de uma transação desfeita no fim
 * (a API cria a linha de brain_settings quando ela não existe).
 */
class CerebroFoto extends Command
{
    protected $signature = 'cerebro:foto {arquivo : JSON de saída} {--banco= : outro arquivo SQLite, só durante este comando}';

    protected $description = 'Grava a resposta da API e o prompt de cada cliente num JSON, para comparar antes e depois';

    public function handle(): int
    {
        if (! UsaOutroBanco::aplicar($this, $this->option('banco'))) {
            return self::FAILURE;
        }

        // Nunca ler a resposta guardada em cache: a foto tem que refletir o banco.
        config(['cache.default' => 'array']);

        $foto = [];

        foreach (Tenant::orderBy('id')->get() as $tenant) {
            DB::beginTransaction();

            try {
                app(CurrentTenant::class)->set($tenant);

                $api = app(BrainApiController::class)()->getData(true);
                unset($api['updated_at']);

                $settings = BrainSetting::first()?->data ?? [];
                $items = BrainItem::orderBy('section')->orderBy('position')->get()->all();

                $foto[$tenant->slug] = [
                    'api' => $api,
                    'prompt_remontado' => PromptBuilder::build($settings, $items),
                ];
            } finally {
                DB::rollBack();
                app(CurrentTenant::class)->set(null);
            }
        }

        $arquivo = UsaOutroBanco::caminho($this->argument('arquivo'));
        @mkdir(dirname($arquivo), 0775, true);
        file_put_contents($arquivo, json_encode($foto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(count($foto).' cliente(s) gravado(s) em '.$arquivo);

        return self::SUCCESS;
    }
}
