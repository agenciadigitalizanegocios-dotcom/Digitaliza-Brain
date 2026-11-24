<?php

namespace App\Brain;

use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\BrainVersion;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Cada alteração no cérebro vira uma versão: foto completa + prompt montado.
 * A API do n8n lê sempre a última versão.
 */
final class Versioner
{
    /** Quantas versões guardar por cliente. */
    private const KEEP = 300;

    public static function record(?User $user, string $description): BrainVersion
    {
        $tenantId = app(CurrentTenant::class)->id()
            ?? throw new RuntimeException('Nenhum cliente selecionado para gravar a versão.');

        $version = DB::transaction(function () use ($user, $description) {
            $settings = BrainSetting::current();
            $settings->version = $settings->version + 1;
            $settings->save();

            $items = BrainItem::query()
                ->orderBy('section')
                ->orderBy('position')
                ->get()
                ->map(fn (BrainItem $item) => [
                    'section' => $item->section,
                    'data' => $item->data,
                    'position' => $item->position,
                    'active' => $item->active,
                ])
                ->all();

            $data = $settings->data ?? [];
            $structure = $settings->structure ?? Templates::fallback();
            $prompt = PromptBuilder::build($data, $items, $structure);

            // Limite de tamanho: protege o custo de IA. Quem chama roda dentro de uma transação,
            // então a alteração inteira é desfeita.
            $max = (int) config('cerebro.limits.prompt_chars', 60000);
            if ($max > 0 && mb_strlen($prompt) > $max) {
                throw ValidationException::withMessages(['limite' => sprintf(
                    'Não salvei: o cérebro ficaria com %s mil caracteres e o máximo é %s mil. Pause ou encurte alguns itens.',
                    number_format(mb_strlen($prompt) / 1000, 1, ',', '.'), number_format($max / 1000, 0, ',', '.')
                )]);
            }

            $version = BrainVersion::create([
                'version' => $settings->version,
                'description' => Str::limit($description, 250),
                'user_id' => $user?->id,
                'snapshot' => ['settings' => $data, 'items' => $items, 'structure' => $structure],
                'prompt' => $prompt,
            ]);

            BrainVersion::where('version', '<=', $settings->version - self::KEEP)->delete();

            return $version;
        });

        Cache::forget(self::cacheKey($tenantId));

        return $version;
    }

    /**
     * Volta o CONTEÚDO para uma versão antiga (e grava isso como uma versão nova).
     * A estrutura continua a atual: o dono não muda a estrutura pelo histórico.
     * Funciona porque as chaves nunca mudam e nada é excluído (o que está arquivado fica escondido).
     */
    public static function restore(BrainVersion $old, ?User $user): BrainVersion
    {
        return DB::transaction(function () use ($old, $user) {
            $settings = BrainSetting::current();
            $settings->data = $old->snapshot['settings'] ?? [];
            $settings->save();

            BrainItem::query()->delete();

            foreach ($old->snapshot['items'] ?? [] as $item) {
                BrainItem::create([
                    'section' => $item['section'],
                    'data' => $item['data'],
                    'position' => $item['position'] ?? 0,
                    'active' => $item['active'] ?? true,
                ]);
            }

            return self::record($user, 'Restaurou a versão '.$old->version);
        });
    }

    public static function cacheKey(int $tenantId): string
    {
        return 'brain:'.$tenantId;
    }
}
