<?php

namespace App\Brain;

use App\Models\BrainItem;
use App\Models\BrainSetting;
use App\Models\BrainTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** O único caminho para dar estrutura a um cliente, e para aplicar a atualização de um modelo. */
final class TemplateApplier
{
    /**
     * Copia a estrutura do modelo para o cliente. Funciona com ou sem a linha de brain_settings existir
     * (nunca cria uma segunda), não mexe em "data" nem na versão, e copia os itens iniciais
     * só se o cliente ainda não tiver nenhum item.
     */
    public static function provision(Tenant $tenant, string $slug): void
    {
        $template = BrainTemplate::where('slug', $slug)->first()
            ?? BrainTemplate::where('slug', 'imobiliaria')->firstOrFail();
        $structure = $template->structure;

        DB::transaction(function () use ($tenant, $template, $structure) {
            $settings = BrainSetting::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['data' => [], 'version' => 0],
            );
            $settings->forceFill(['structure' => $structure, 'template_base' => $structure])->save();

            $tenant->forceFill([
                'template_id' => $template->id,
                'template_version' => $template->version,
                'modules' => $tenant->modules ?? array_fill_keys(array_keys(Modules::ALL), false),
            ])->save();

            $hasItems = BrainItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists();
            if (! $hasItems) {
                $positions = [];
                foreach ($structure['starter_items'] ?? [] as $item) {
                    $section = $item['section'];
                    $positions[$section] = ($positions[$section] ?? 0) + 1;
                    BrainItem::withoutGlobalScopes()->create([
                        'tenant_id' => $tenant->id,
                        'section' => $section,
                        'data' => $item['data'],
                        'position' => $positions[$section],
                        'active' => true,
                    ]);
                }
            }
        });

        // Tipos de produto do modelo (Imóvel...), só se o cliente ainda não tiver nenhum.
        \App\Catalog\Catalog::provision($tenant, $structure);

        Structure::forget($tenant->id);
    }

    /** Lista do que mudou no modelo desde a última aplicação neste cliente. */
    public static function changes(Tenant $tenant): array
    {
        $template = $tenant->template;
        if (! $template) {
            return [];
        }
        $settings = BrainSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $base = $settings?->template_base ?? $template->structure;
        $cli = $settings?->structure ?? Templates::fallback();

        return TemplateDiff::changes($base, $template->structure, $cli);
    }

    /** Aplica as mudanças escolhidas. Nunca apaga conteúdo. Gera versão no histórico. */
    public static function applyUpdate(Tenant $tenant, array $selectedIds, ?User $user): void
    {
        $template = $tenant->template;
        abort_unless($template, 404);

        app(CurrentTenant::class)->set($tenant);
        $settings = BrainSetting::current();
        $cli = $settings->structure ?? Templates::fallback();
        $changes = TemplateDiff::changes($settings->template_base ?? $template->structure, $template->structure, $cli);
        $new = TemplateDiff::apply($cli, $template->structure, $changes, $selectedIds);

        $errors = StructureValidator::validate($new, $cli, fn ($tab, $field) => self::countLong($tenant, $tab, $field));
        if ($errors) {
            throw ValidationException::withMessages(['structure' => $errors]);
        }

        DB::transaction(function () use ($settings, $new, $template, $tenant, $user) {
            $settings->forceFill(['structure' => $new, 'template_base' => $template->structure])->save();
            $tenant->forceFill(['template_version' => $template->version])->save();
            Structure::forget($tenant->id);

            Versioner::record($user, "Estrutura: aplicou a atualização do modelo {$template->name} (v{$template->version})");
        });
        Structure::forget($tenant->id);
    }

    /** Tira o selo de pendente sem mudar nada no cliente. */
    public static function dismiss(Tenant $tenant): void
    {
        $template = $tenant->template;
        abort_unless($template, 404);

        BrainSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->update(['template_base' => json_encode($template->structure)]);
        $tenant->forceFill(['template_version' => $template->version])->save();
    }

    /** Quantos valores guardados (conteúdo atual, itens pausados incluídos) passam de 500 caracteres. */
    public static function countLong(Tenant $tenant, string $tabKey, string $fieldKey): int
    {
        $count = 0;
        $settings = BrainSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        if (mb_strlen((string) ($settings?->data[$fieldKey] ?? '')) > 500) {
            $count++;
        }
        BrainItem::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('section', $tabKey)
            ->each(function (BrainItem $item) use (&$count, $fieldKey) {
                if (mb_strlen((string) ($item->data[$fieldKey] ?? '')) > 500) {
                    $count++;
                }
            });

        return $count;
    }
}
