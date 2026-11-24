<?php

namespace App\Brain;

use App\Fields\FieldTypes;

/**
 * Regras da estrutura (abas e campos). Devolve a lista de erros em português; vazia = ok.
 * Recebe a estrutura nova e, quando houver, a anterior (para garantir que nada some nem muda de chave).
 */
final class StructureValidator
{
    public const KEY_REGEX = '/^[a-z][a-z0-9_]{0,39}$/';

    /** A chave da aba vira URL em /cerebro/{tab}. */
    public const RESERVED = ['versoes', 'itens', 'estrutura', 'modulos', 'admin'];

    /**
     * @param  callable|null  $countLong  fn(string $tab, string $field): int — quantos valores guardados passam de 500 caracteres
     */
    public static function validate(array $new, ?array $old = null, ?callable $countLong = null): array
    {
        $errors = [];
        $tabs = $new['tabs'] ?? [];

        if (! array_filter($tabs, fn ($t) => empty($t['archived']))) {
            $errors[] = 'Precisa haver pelo menos uma aba ativa.';
        }

        $tabKeys = [];
        $settingsFieldKeys = [];
        $coreSeen = [];

        foreach ($tabs as $tab) {
            $key = $tab['key'] ?? '';
            $label = $tab['label'] ?? $key;

            if (! preg_match(self::KEY_REGEX, $key)) {
                $errors[] = "A chave da aba “{$label}” é inválida.";
            }
            if (in_array($key, self::RESERVED, true)) {
                $errors[] = "A chave “{$key}” é reservada pelo sistema.";
            }
            if (isset($tabKeys[$key])) {
                $errors[] = "Há duas abas com a chave “{$key}”.";
            }
            $tabKeys[$key] = true;

            if (! in_array($tab['type'] ?? '', ['settings', 'items'], true)) {
                $errors[] = "A aba “{$label}” precisa ser formulário ou lista.";
                continue;
            }

            $format = $tab['ai']['format'] ?? '';
            $isGeneric = in_array($format, PromptFormats::GENERIC, true);
            if (! $isGeneric && ! isset(PromptFormats::USES[$format])) {
                $errors[] = "Formato do prompt inválido na aba “{$label}”.";
                continue;
            }
            $expectedType = $isGeneric ? ($format === 'campos' ? 'settings' : 'items') : PromptFormats::USES[$format]['type'];
            if ($expectedType !== $tab['type']) {
                $errors[] = "O formato “".(PromptFormats::LABELS[$format] ?? $format)."” não combina com uma aba de ".($tab['type'] === 'items' ? 'lista' : 'formulário')." (aba “{$label}”).";
            }

            if (PromptFormats::isCore($format)) {
                if (! empty($tab['archived'])) {
                    $errors[] = "A aba “{$label}” faz parte do núcleo e não pode ser arquivada.";
                }
                if (isset($coreSeen[$format])) {
                    $errors[] = 'Só pode existir uma aba com o formato “'.PromptFormats::LABELS[$format].'”.';
                }
                $coreSeen[$format] = true;
            }

            if (! is_int($tab['ai']['order'] ?? null)) {
                $errors[] = "A ordem no prompt da aba “{$label}” precisa ser um número.";
            }

            $fieldKeys = [];
            $active = [];
            foreach ($tab['fields'] ?? [] as $field) {
                $fk = $field['key'] ?? '';
                $fl = $field['label'] ?? $fk;
                if (! preg_match(self::KEY_REGEX, $fk)) {
                    $errors[] = "A chave do campo “{$fl}” (aba “{$label}”) é inválida.";
                }
                if (isset($fieldKeys[$fk])) {
                    $errors[] = "Há dois campos com a chave “{$fk}” na aba “{$label}”.";
                }
                $fieldKeys[$fk] = true;

                if ($tab['type'] === 'settings') {
                    if (isset($settingsFieldKeys[$fk])) {
                        $errors[] = "A chave “{$fk}” já é usada na aba “{$settingsFieldKeys[$fk]}”; nas abas de formulário as chaves não podem se repetir.";
                    }
                    $settingsFieldKeys[$fk] = $label;
                }

                if (! FieldTypes::exists($field['type'] ?? '')) {
                    $errors[] = "Tipo inválido no campo “{$fl}”.";
                }
                if (($field['type'] ?? '') === 'select') {
                    $options = $field['options'] ?? [];
                    if (! $options) {
                        $errors[] = "O campo “{$fl}” precisa de pelo menos uma opção.";
                    }
                    foreach (array_keys($options) as $ok) {
                        if (! preg_match(self::KEY_REGEX, (string) $ok)) {
                            $errors[] = "A chave da opção “{$ok}” do campo “{$fl}” é inválida.";
                        }
                    }
                }
                if (empty($field['archived'])) {
                    $active[$fk] = $field;
                }
            }

            // Campos que o formato específico usa: precisam existir, ativos, com o tipo certo.
            if (! $isGeneric) {
                foreach (PromptFormats::USES[$format]['fields'] as $fk => $types) {
                    if (! isset($active[$fk])) {
                        $errors[] = "A aba “{$label}” usa o formato “".PromptFormats::LABELS[$format]."”, que precisa do campo “{$fk}” ativo".(PromptFormats::isCore($format) ? '.' : '. Troque o formato para o genérico se quiser arquivá-lo.');
                    } elseif (! in_array($active[$fk]['type'], $types, true)) {
                        $errors[] = "O campo “{$active[$fk]['label']}” precisa ser do tipo ".implode(' ou ', array_map(fn ($t) => FieldTypes::TYPES[$t], $types)).'.';
                    }
                }
                foreach (PromptFormats::LOCKED_OPTIONS[$format] ?? [] as $fk => $optKeys) {
                    $f = $active[$fk] ?? null;
                    foreach ($optKeys as $optKey) {
                        if ($f && (! isset($f['options'][$optKey]) || in_array($optKey, $f['archived_options'] ?? [], true))) {
                            $errors[] = "A opção “{$optKey}” do campo “{$f['label']}” é usada pelo prompt e não pode sair.";
                        }
                    }
                }
            }

            if ($tab['type'] === 'items' && empty($tab['archived'])) {
                $tf = $tab['title_field'] ?? null;
                if (! $tf || ! isset($active[$tf]) || ! in_array($active[$tf]['type'], ['text', 'textarea', 'select'], true)) {
                    $errors[] = "Escolha o campo título da aba “{$label}” (texto ou lista de opções, ativo).";
                }
                $bf = $tab['badge_field'] ?? null;
                if ($bf && (! isset($active[$bf]) || ! in_array($active[$bf]['type'], ['text', 'number', 'select', 'checkbox', 'money'], true))) {
                    $errors[] = "O campo do selo da aba “{$label}” precisa estar ativo e ser texto curto, número, opções, sim/não ou dinheiro.";
                }
            }
        }

        foreach (PromptFormats::CORE as $core) {
            if (! isset($coreSeen[$core])) {
                $errors[] = 'Falta a aba do núcleo com o formato “'.PromptFormats::LABELS[$core].'”.';
            }
        }

        if ($old) {
            $errors = array_merge($errors, self::compareWithOld($new, $old, $countLong));
        }

        return array_values(array_unique($errors));
    }

    /** Nada some, chaves não mudam, núcleo não troca de formato, tipo só muda entre texto curto e longo. */
    private static function compareWithOld(array $new, array $old, ?callable $countLong): array
    {
        $errors = [];
        $newTabs = [];
        foreach ($new['tabs'] ?? [] as $t) {
            $newTabs[$t['key']] = $t;
        }

        foreach ($old['tabs'] ?? [] as $ot) {
            $nt = $newTabs[$ot['key']] ?? null;
            if (! $nt) {
                $errors[] = "A aba “{$ot['label']}” não pode ser excluída (arquive em vez de excluir).";
                continue;
            }
            $oldFormat = $ot['ai']['format'] ?? '';
            if (PromptFormats::isCore($oldFormat) && ($nt['ai']['format'] ?? '') !== $oldFormat) {
                $errors[] = "A aba “{$ot['label']}” é do núcleo: o formato do prompt não pode ser trocado.";
            }
            if (($nt['type'] ?? '') !== ($ot['type'] ?? '')) {
                $errors[] = "A aba “{$ot['label']}” não pode trocar entre formulário e lista.";
            }

            $nf = [];
            foreach ($nt['fields'] ?? [] as $f) {
                $nf[$f['key']] = $f;
            }
            foreach ($ot['fields'] ?? [] as $of) {
                $f = $nf[$of['key']] ?? null;
                if (! $f) {
                    $errors[] = "O campo “{$of['label']}” não pode ser excluído (arquive em vez de excluir).";
                    continue;
                }
                if ($f['type'] !== $of['type']) {
                    $pair = $of['type'].'>'.$f['type'];
                    if ($pair === 'textarea>text') {
                        $long = $countLong ? (int) $countLong($ot['key'], $of['key']) : 0;
                        if ($long > 0) {
                            $errors[] = "{$long} ".($long === 1 ? 'item tem' : 'itens têm')." mais de 500 caracteres em “{$of['label']}”; encurte-os antes de trocar para texto curto.";
                        }
                    } elseif ($pair !== 'text>textarea') {
                        $errors[] = "O campo “{$of['label']}” não pode mudar de tipo (só entre texto curto e texto longo).";
                    }
                }
                foreach (array_keys($of['options'] ?? []) as $optKey) {
                    if (! array_key_exists($optKey, $f['options'] ?? [])) {
                        $errors[] = "A opção “{$optKey}” do campo “{$of['label']}” não pode ser excluída (arquive em vez de excluir).";
                    }
                }
            }
        }

        return $errors;
    }

    /** Gera uma chave a partir do nome, livre entre as existentes (ativas ou arquivadas), com no máximo 40 caracteres. */
    public static function makeKey(string $label, array $taken, string $fallback = 'campo'): string
    {
        // Str::ascii (Laravel) dá o mesmo resultado no Windows e no Linux; iconv só como reserva.
        $ascii = strtolower(class_exists(\Illuminate\Support\Str::class)
            ? \Illuminate\Support\Str::ascii($label)
            : (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label));
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = $fallback.($base !== '' ? '_'.$base : '');
        }
        $base = substr($base, 0, 40);
        $base = rtrim($base, '_');

        $candidate = $base;
        $n = 2;
        while (in_array($candidate, $taken, true) || in_array($candidate, self::RESERVED, true)) {
            $suffix = '_'.$n++;
            $candidate = rtrim(substr($base, 0, 40 - strlen($suffix)), '_').$suffix;
        }

        return $candidate;
    }
}
