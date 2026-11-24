<?php

namespace App\Brain;

use App\Fields\FieldTypes;
use App\Models\BrainSetting;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\Log;

/**
 * A estrutura (abas e campos) do cliente atual. Substitui o antigo Sections.php.
 * Por padrão esconde o que está arquivado.
 */
final class Structure
{
    private static array $memo = [];

    public function __construct(private array $data) {}

    /** Estrutura do cliente da requisição atual. */
    public static function current(): self
    {
        $tenantId = app(CurrentTenant::class)->id() ?? 0;

        if (! isset(self::$memo[$tenantId])) {
            $structure = BrainSetting::current()->structure;

            if (! $structure) {
                Log::warning('Cliente sem estrutura gravada; usando o modelo Imobiliária.', ['tenant_id' => $tenantId]);
                $structure = Templates::fallback();
            }

            self::$memo[$tenantId] = new self($structure);
        }

        return self::$memo[$tenantId];
    }

    /** Esquece a estrutura guardada em memória (chamar depois de gravar). */
    public static function forget(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[$tenantId]);
        }
    }

    public function raw(): array
    {
        return $this->data;
    }

    /** Abas na ordem do menu, por chave. */
    public function tabs(bool $withArchived = false): array
    {
        $out = [];
        foreach ($this->data['tabs'] ?? [] as $tab) {
            if ($withArchived || empty($tab['archived'])) {
                $out[$tab['key']] = $tab;
            }
        }

        return $out;
    }

    /** Uma aba ativa (com os campos ativos já filtrados em "fields"). */
    public function tab(string $key, bool $withArchived = false): ?array
    {
        $tab = $this->tabs($withArchived)[$key] ?? null;
        if (! $tab) {
            return null;
        }
        $tab['all_fields'] = $tab['fields'] ?? [];
        $tab['fields'] = self::activeFields($tab);

        return $tab;
    }

    public function firstTabKey(): ?string
    {
        return array_key_first($this->tabs());
    }

    /** Campos ativos de todas as abas de formulário ativas, por chave. */
    public function settingsFields(): array
    {
        $out = [];
        foreach ($this->tabs() as $tab) {
            if ($tab['type'] === 'settings') {
                foreach (self::activeFields($tab) as $f) {
                    $out[$f['key']] = $f;
                }
            }
        }

        return $out;
    }

    public static function activeFields(array $tab): array
    {
        return array_values(array_filter($tab['fields'] ?? [], fn ($f) => empty($f['archived'])));
    }

    /** Campo de uma aba (ativo ou não). */
    public static function field(array $tab, string $key): ?array
    {
        foreach ($tab['all_fields'] ?? $tab['fields'] ?? [] as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }

        return null;
    }

    /** Nome curto do item, usado no histórico. */
    public static function itemTitle(array $tab, array $data): string
    {
        $key = $tab['title_field'] ?? null;
        $field = $key ? self::field($tab, $key) : null;
        $title = $field ? FieldTypes::display($field, $data[$key] ?? null) : '';

        return mb_strimwidth($title ?: 'item', 0, 60, '…');
    }
}
