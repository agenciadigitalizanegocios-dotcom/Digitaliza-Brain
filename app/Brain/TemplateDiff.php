<?php

namespace App\Brain;

/**
 * Compara, por chave, três versões da estrutura:
 *   base = o que o cliente recebeu do modelo da última vez
 *   tpl  = o modelo como está agora
 *   cli  = a estrutura do cliente como está agora
 * e lista o que mudou no modelo, já marcando o que é seguro aplicar.
 * Aplicar nunca apaga conteúdo e nunca toca abas/campos criados só no cliente.
 */
final class TemplateDiff
{
    public const TAB_ATTRS = ['label', 'icon', 'intro', 'singular', 'ordered', 'title_field', 'badge_field', 'badge_text', 'archived'];

    public const AI_ATTRS = ['format', 'title', 'before', 'after', 'line', 'order'];

    public const FIELD_ATTRS = ['label', 'type', 'required', 'placeholder', 'help', 'options', 'archived_options', 'default', 'min', 'max', 'send_to_ai', 'archived'];

    public const NAMES = [
        'label' => 'nome', 'icon' => 'ícone', 'intro' => 'texto de ajuda', 'singular' => 'nome no singular', 'ordered' => 'em ordem',
        'title_field' => 'campo título', 'badge_field' => 'campo do selo', 'badge_text' => 'texto do selo', 'archived' => 'arquivada',
        'ai.format' => 'formato do prompt', 'ai.title' => 'título no prompt', 'ai.before' => 'texto antes (IA)', 'ai.after' => 'texto depois (IA)',
        'ai.line' => 'modelo de linha (IA)', 'ai.order' => 'ordem no prompt', 'type' => 'tipo', 'required' => 'obrigatório',
        'placeholder' => 'exemplo', 'help' => 'ajuda', 'options' => 'opções', 'archived_options' => 'opções arquivadas', 'default' => 'padrão',
        'min' => 'mínimo', 'max' => 'máximo', 'send_to_ai' => 'vai para a IA',
    ];

    /** @return array<int, array{id:string, op:string, label:string, checked:bool, applicable:bool, warning:?string, cli:mixed, tpl:mixed, tab:string, field:?string, attr:?string}> */
    public static function changes(array $base, array $tpl, array $cli): array
    {
        $out = [];
        $b = self::index($base);
        $t = self::index($tpl);
        $c = self::index($cli);

        foreach ($t as $tabKey => $tTab) {
            $tabLabel = $tTab['label'];

            // Aba nova no modelo.
            if (! isset($b[$tabKey])) {
                if (isset($c[$tabKey])) {
                    $out[] = self::change("conflict:tab:$tabKey", 'conflict', $tabKey, null, null,
                        "Aba nova “{$tabLabel}” no modelo", false, false,
                        "O cliente já tem uma aba com a chave “{$tabKey}”. Renomeie a chave no modelo ou resolva à mão.");
                } else {
                    $out[] = self::change("add_tab:$tabKey", 'add_tab', $tabKey, null, null, "Aba nova: “{$tabLabel}”", true, true);
                }
                continue;
            }

            $bTab = $b[$tabKey];
            $cTab = $c[$tabKey] ?? null;
            if (! $cTab) {
                continue;
            }

            // Atributos da aba e da IA.
            foreach (self::TAB_ATTRS as $attr) {
                self::attrChange($out, "tab:$tabKey:$attr", 'set_tab', $tabKey, null, $attr,
                    $bTab[$attr] ?? null, $tTab[$attr] ?? null, $cTab[$attr] ?? null, "Aba “{$tabLabel}”");
            }
            foreach (self::AI_ATTRS as $attr) {
                self::attrChange($out, "tab:$tabKey:ai.$attr", 'set_tab', $tabKey, null, "ai.$attr",
                    $bTab['ai'][$attr] ?? null, $tTab['ai'][$attr] ?? null, $cTab['ai'][$attr] ?? null, "Aba “{$tabLabel}”");
            }

            // Campos.
            $bf = self::fields($bTab);
            $cf = self::fields($cTab);
            foreach (self::fields($tTab) as $fk => $tField) {
                if (! isset($bf[$fk])) {
                    if (isset($cf[$fk])) {
                        $out[] = self::change("conflict:field:$tabKey:$fk", 'conflict', $tabKey, $fk, null,
                            "Campo novo “{$tField['label']}” em “{$tabLabel}”", false, false,
                            "O cliente já tem “{$fk}” (tipo {$cf[$fk]['type']}). Renomeie a chave no modelo ou resolva à mão.");
                    } else {
                        $out[] = self::change("add_field:$tabKey:$fk", 'add_field', $tabKey, $fk, null,
                            "Campo novo “{$tField['label']}” em “{$tabLabel}”", true, true);
                    }
                    continue;
                }
                if (! isset($cf[$fk])) {
                    continue;
                }
                foreach (self::FIELD_ATTRS as $attr) {
                    self::attrChange($out, "field:$tabKey:$fk:$attr", 'set_field', $tabKey, $fk, $attr,
                        $bf[$fk][$attr] ?? null, $tField[$attr] ?? null, $cf[$fk][$attr] ?? null, "Campo “{$tField['label']}” (aba “{$tabLabel}”)");
                }
            }
        }

        // Fechamento do prompt.
        self::attrChange($out, 'closing', 'set_closing', '', null, 'closing',
            $base['closing'] ?? [], $tpl['closing'] ?? [], $cli['closing'] ?? [], 'Fechamento do prompt');

        return $out;
    }

    /** Aplica as mudanças escolhidas (pelos ids) e devolve a estrutura nova do cliente. */
    public static function apply(array $cli, array $tpl, array $changes, array $selectedIds): array
    {
        $tplIndex = self::index($tpl);
        $selected = array_flip($selectedIds);

        foreach ($changes as $ch) {
            if (! $ch['applicable'] || ! isset($selected[$ch['id']])) {
                continue;
            }

            switch ($ch['op']) {
                case 'add_tab':
                    $cli['tabs'][] = $tplIndex[$ch['tab']];
                    break;

                case 'add_field':
                    foreach ($cli['tabs'] as &$tab) {
                        if ($tab['key'] === $ch['tab']) {
                            $tab['fields'][] = self::fields($tplIndex[$ch['tab']])[$ch['field']];
                        }
                    }
                    unset($tab);
                    break;

                case 'set_tab':
                    foreach ($cli['tabs'] as &$tab) {
                        if ($tab['key'] === $ch['tab']) {
                            if (str_starts_with($ch['attr'], 'ai.')) {
                                $tab['ai'][substr($ch['attr'], 3)] = $ch['tpl'];
                            } else {
                                $tab[$ch['attr']] = $ch['tpl'];
                            }
                        }
                    }
                    unset($tab);
                    break;

                case 'set_field':
                    foreach ($cli['tabs'] as &$tab) {
                        if ($tab['key'] !== $ch['tab']) {
                            continue;
                        }
                        foreach ($tab['fields'] as &$field) {
                            if ($field['key'] === $ch['field']) {
                                // Opções: as do modelo + as que existem só no cliente (nada some).
                                $field[$ch['attr']] = $ch['attr'] === 'options'
                                    ? ($ch['tpl'] ?? []) + ($field['options'] ?? [])
                                    : $ch['tpl'];
                            }
                        }
                        unset($field);
                    }
                    unset($tab);
                    break;

                case 'set_closing':
                    $cli['closing'] = $ch['tpl'];
                    break;
            }
        }

        return $cli;
    }

    private static function attrChange(array &$out, string $id, string $op, string $tab, ?string $field, string $attr, mixed $base, mixed $tpl, mixed $cli, string $where): void
    {
        if ($tpl == $base || $cli == $tpl) {
            return; // o modelo não mudou isso, ou o cliente já está igual ao modelo
        }

        $personalized = $cli != $base;
        $archiving = $attr === 'archived' && $tpl === true;
        $name = self::NAMES[$attr] ?? $attr;

        $warning = null;
        if ($personalized) {
            $warning = 'Personalizado no cliente: “'.self::show($cli).'” · no modelo: “'.self::show($tpl).'”';
        }
        if ($archiving) {
            $warning = trim(($warning ? $warning.'. ' : '').'Arquivar tira esse conteúdo do prompt.');
        }

        $out[] = self::change($id, $op, $tab, $field, $attr,
            "{$where}: {$name} → “".self::show($tpl).'”',
            ! $personalized && ! $archiving, true, $warning, $cli, $tpl);
    }

    private static function change(string $id, string $op, string $tab, ?string $field, ?string $attr, string $label, bool $checked, bool $applicable, ?string $warning = null, mixed $cli = null, mixed $tpl = null): array
    {
        return compact('id', 'op', 'tab', 'field', 'attr', 'label', 'checked', 'applicable', 'warning', 'cli', 'tpl');
    }

    private static function index(array $structure): array
    {
        $out = [];
        foreach ($structure['tabs'] ?? [] as $tab) {
            $out[$tab['key']] = $tab;
        }

        return $out;
    }

    private static function fields(array $tab): array
    {
        $out = [];
        foreach ($tab['fields'] ?? [] as $f) {
            $out[$f['key']] = $f;
        }

        return $out;
    }

    private static function show(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'sim' : 'não';
        }
        if (is_array($v)) {
            return mb_strimwidth(implode(' · ', array_map(fn ($x) => is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string) $x, $v)), 0, 120, '…');
        }

        return mb_strimwidth((string) ($v ?? ''), 0, 120, '…');
    }
}
