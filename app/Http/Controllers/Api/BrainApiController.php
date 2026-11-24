<?php

namespace App\Http\Controllers\Api;

use App\Brain\PromptBuilder;
use App\Brain\Structure;
use App\Brain\Templates;
use App\Brain\Versioner;
use App\Fields\FieldTypes;
use App\Http\Controllers\Controller;
use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\BrainVersion;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/v1/brain
 * Devolve o cérebro da imobiliária dona do token, com o prompt pronto.
 * As chaves de topo e o formato continuam os mesmos de antes; só entrou "modules".
 * Fica em cache por 5 minutos; qualquer alteração no painel limpa o cache na hora.
 */
class BrainApiController extends Controller
{
    public function __invoke()
    {
        $tenant = app(CurrentTenant::class)->get();

        $brain = Cache::remember(
            Versioner::cacheKey($tenant->id),
            now()->addMinutes(5),
            fn () => $this->payload()
        );

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
            ],
            // Se false, o n8n não deve responder (cliente suspenso).
            'active' => $tenant->isActive(),
        ] + $brain + [
            // Ferramentas ligadas para este cliente (Parte 2: catálogo, Parte 3: agenda).
            'modules' => $tenant->moduleFlags(),
        ]);
    }

    private function payload(): array
    {
        $version = BrainVersion::latest('version')->first();

        if ($version) {
            $settings = $version->snapshot['settings'] ?? [];
            $items = $version->snapshot['items'] ?? [];
            $structure = $version->snapshot['structure'] ?? Structure::current()->raw();
            $prompt = $version->prompt;
            $number = $version->version;
            $updatedAt = $version->created_at?->toIso8601String();
        } else {
            $current = BrainSetting::current();
            $settings = $current->data ?? [];
            $items = BrainItem::orderBy('position')->get()->all();
            $structure = $current->structure ?? Templates::fallback();
            $prompt = PromptBuilder::build($settings, $items, $structure);
            $number = 0;
            $updatedAt = null;
        }

        [$tabs, $settingsFields, $archivedSettings] = $this->index($structure);
        $url = fn (string $path) => Storage::disk('public')->url($path);

        // settings: objeto plano {chave: valor}, como antes. Imagem sai como URL, dinheiro em centavos.
        // Campos arquivados (e de abas arquivadas) não saem.
        foreach ($settings as $key => $value) {
            if (isset($archivedSettings[$key])) {
                unset($settings[$key]);
            } elseif (isset($settingsFields[$key])) {
                $settings[$key] = FieldTypes::apiValue($settingsFields[$key], $value, $url);
            }
        }

        // sections: {chave_da_aba: [ {dados}, ... ]}, só itens ativos, na ordem. Abas e campos arquivados saem.
        $sections = [];
        foreach (PromptBuilder::groupActive($items) as $section => $list) {
            $tab = $tabs[$section] ?? null;
            if ($tab && ! empty($tab['archived'])) {
                continue;
            }
            $sections[$section] = array_map(function ($item) use ($tab, $url) {
                $data = $item['data'];
                if ($tab) {
                    foreach ($tab['fields'] ?? [] as $f) {
                        if (! array_key_exists($f['key'], $data)) {
                            continue;
                        }
                        if (! empty($f['archived'])) {
                            unset($data[$f['key']]);
                        } else {
                            $data[$f['key']] = FieldTypes::apiValue($f, $data[$f['key']], $url);
                        }
                    }
                }

                return $data;
            }, $list);
        }

        return [
            'version' => $number,
            'updated_at' => $updatedAt,
            'bot_name' => $settings['bot_name'] ?? null,
            'greeting' => $settings['greeting'] ?? null,
            'max_bot_replies' => isset($settings['max_bot_replies']) ? (int) $settings['max_bot_replies'] : null,
            'handoff_message' => $settings['handoff_message'] ?? null,
            'prompt' => $prompt,
            'settings' => $settings,
            'sections' => $sections,
        ];
    }

    /** Abas por chave, campos de formulário ativos por chave e chaves arquivadas. */
    private function index(array $structure): array
    {
        $tabs = [];
        $settingsFields = [];
        $archived = [];
        foreach ($structure['tabs'] ?? [] as $tab) {
            $tabs[$tab['key']] = $tab;
            if ($tab['type'] === 'settings') {
                foreach ($tab['fields'] ?? [] as $f) {
                    if (empty($f['archived']) && empty($tab['archived'])) {
                        $settingsFields[$f['key']] = $f;
                    } else {
                        $archived[$f['key']] = true;
                    }
                }
            }
        }
        // Uma chave ativa em outra aba de formulário não conta como arquivada.
        $archived = array_diff_key($archived, $settingsFields);

        return [$tabs, $settingsFields, $archived];
    }
}
