<?php

namespace App\Catalog;

use App\Brain\StructureValidator;
use App\Fields\FieldTypes;

/**
 * Regras de um tipo de produto (Imóvel, Armação, Curso...). Devolve a lista de erros; vazia = ok.
 * Mesmas garantias do cérebro: chaves fixas, nada é excluído (só arquivado), tipo só troca entre
 * texto curto e longo.
 */
final class ProductTypeValidator
{
    /** Teto de campos no total (arquivados contam) e de campos ativos (o que aparece no formulário). */
    public const MAX_FIELDS = 40;

    public const MAX_ACTIVE_FIELDS = 25;

    public const MAX_CARD_FIELDS = 4;

    public const MAX_FILTERS = 6;

    public const MAX_OPTIONS = 30;

    public const MAX_TYPES = 5;

    /** Nomes usados pela vitrine e pela API: nenhum campo pode ter essa chave (nem terminar em _min/_max). */
    public const RESERVED_KEYS = ['q', 'produto', 'ordem', 'limite', 'page'];

    public const STATUSES = ['disponivel', 'reservado', 'indisponivel'];

    /** Tipos de campo que podem virar filtro na vitrine e na busca do robô. */
    public const FILTERABLE = ['text', 'select', 'number', 'money', 'checkbox'];

    public static function validate(array $type, ?array $old = null, array $otherKeys = []): array
    {
        $errors = [];
        $name = $type['name'] ?? '';

        if (! preg_match(StructureValidator::KEY_REGEX, $type['key'] ?? '')) {
            $errors[] = 'Chave do tipo de produto inválida.';
        }
        if (in_array($type['key'] ?? '', $otherKeys, true)) {
            $errors[] = "Já existe um tipo de produto com a chave “{$type['key']}”.";
        }
        if (trim($name) === '') {
            $errors[] = 'Dê um nome ao tipo de produto.';
        }

        $fields = $type['fields'] ?? [];
        if (count($fields) > self::MAX_FIELDS) {
            $errors[] = 'No máximo '.self::MAX_FIELDS.' campos por tipo de produto.';
        }

        $keys = [];
        $active = [];
        $labels = [];
        $oldKeys = array_column($old['fields'] ?? [], 'key');
        foreach ($fields as $f) {
            $fk = $f['key'] ?? '';
            $fl = $f['label'] ?? $fk;
            if (! preg_match(StructureValidator::KEY_REGEX, $fk)) {
                $errors[] = "A chave do campo “{$fl}” é inválida.";
            }
            if (! in_array($fk, $oldKeys, true) && self::isReservedKey($fk)) {
                $errors[] = "A chave “{$fk}” é reservada pelo sistema. Use outro nome no campo “{$fl}”.";
            }
            if (($f['type'] ?? '') === 'image' && ! in_array($fk, $oldKeys, true)) {
                $errors[] = "Produto não usa campo de imagem (as fotos ficam na galeria). Troque o tipo do campo “{$fl}”.";
            }
            if (count($f['options'] ?? []) > self::MAX_OPTIONS) {
                $errors[] = "O campo “{$fl}” passou de ".self::MAX_OPTIONS.' opções.';
            }
            if (empty($f['archived'])) {
                $norm = mb_strtolower(trim((string) $fl));
                if (isset($labels[$norm])) {
                    $errors[] = "Já existe um campo chamado “{$fl}”. Use outro nome.";
                }
                $labels[$norm] = true;
            }
            if (isset($keys[$fk])) {
                $errors[] = "Há dois campos com a chave “{$fk}”.";
            }
            $keys[$fk] = true;
            if (! FieldTypes::exists($f['type'] ?? '')) {
                $errors[] = "Tipo inválido no campo “{$fl}”.";
            }
            if (($f['type'] ?? '') === 'select' && empty($f['options'])) {
                $errors[] = "O campo “{$fl}” precisa de pelo menos uma opção.";
            }
            if (empty($f['archived'])) {
                $active[$fk] = $f;
            }
        }

        if (count($active) > self::MAX_ACTIVE_FIELDS) {
            $errors[] = 'No máximo '.self::MAX_ACTIVE_FIELDS.' campos ativos. Arquive um campo que não usa para criar outro.';
        }

        $tf = $type['title_field'] ?? null;
        if (! $tf || ! isset($active[$tf]) || ! in_array($active[$tf]['type'], ['text', 'textarea'], true)) {
            $errors[] = 'Escolha o campo título (texto, ativo).';
        } elseif (! self::isPublic($active[$tf])) {
            $errors[] = 'O campo título aparece na vitrine: não pode ser um campo interno.';
        }

        $pf = $type['price_field'] ?? null;
        if ($pf && (! isset($active[$pf]) || $active[$pf]['type'] !== 'money')) {
            $errors[] = 'O campo de preço precisa ser um campo de dinheiro ativo.';
        } elseif ($pf && ! self::isPublic($active[$pf])) {
            $errors[] = 'O campo de preço aparece na vitrine: não pode ser um campo interno.';
        }

        if (count($type['filters'] ?? []) > self::MAX_FILTERS) {
            $errors[] = 'No máximo '.self::MAX_FILTERS.' filtros.';
        }
        foreach ($type['filters'] ?? [] as $k) {
            if (! isset($active[$k]) || ! in_array($active[$k]['type'], self::FILTERABLE, true)) {
                $errors[] = "O filtro “{$k}” precisa ser um campo ativo de texto curto, opções, número, dinheiro ou sim/não.";
            } elseif (! self::isPublic($active[$k]) || ($active[$k]['send_to_ai'] ?? true) === false) {
                $errors[] = "O campo “{$active[$k]['label']}” é interno: para virar filtro precisa aparecer na vitrine e ir para o robô.";
            }
        }
        if (count($type['card_fields'] ?? []) > self::MAX_CARD_FIELDS) {
            $errors[] = 'No máximo '.self::MAX_CARD_FIELDS.' campos no cartão da vitrine.';
        }
        foreach ($type['card_fields'] ?? [] as $k) {
            if (! isset($active[$k]) || in_array($active[$k]['type'], ['image', 'textarea'], true)) {
                $errors[] = "O campo “{$k}” do cartão precisa estar ativo (e não pode ser imagem nem texto longo).";
            } elseif (! self::isPublic($active[$k])) {
                $errors[] = "O campo “{$active[$k]['label']}” é interno e não pode aparecer no cartão.";
            }
        }
        foreach (self::STATUSES as $st) {
            if (trim((string) ($type['status_labels'][$st] ?? '')) === '') {
                $errors[] = 'Preencha os nomes dos três status.';
                break;
            }
        }

        if ($old) {
            $new = [];
            foreach ($fields as $f) {
                $new[$f['key']] = $f;
            }
            foreach ($old['fields'] ?? [] as $of) {
                $f = $new[$of['key']] ?? null;
                if (! $f) {
                    $errors[] = "O campo “{$of['label']}” não pode ser excluído (arquive em vez de excluir).";
                    continue;
                }
                if ($f['type'] !== $of['type'] && ! in_array($of['type'].'>'.$f['type'], ['text>textarea', 'textarea>text'], true)) {
                    $errors[] = "O campo “{$of['label']}” não pode mudar de tipo (só entre texto curto e texto longo).";
                }
                foreach (array_keys($of['options'] ?? []) as $optKey) {
                    if (! array_key_exists($optKey, $f['options'] ?? [])) {
                        $errors[] = "A opção “{$optKey}” do campo “{$of['label']}” não pode ser excluída (arquive em vez de excluir).";
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    public static function isReservedKey(string $key): bool
    {
        return in_array($key, self::RESERVED_KEYS, true) || (bool) preg_match('/_(min|max)$/', $key);
    }

    /** Chave nova para um campo: única, sem nomes reservados. */
    public static function newFieldKey(string $label, array $taken): string
    {
        $taken = array_merge($taken, self::RESERVED_KEYS);
        $key = StructureValidator::makeKey($label, $taken);
        while (self::isReservedKey($key)) {
            $key = StructureValidator::makeKey($key.'_campo', $taken);
        }

        return $key;
    }

    private static function isPublic(array $field): bool
    {
        return ($field['public'] ?? true) !== false;
    }
}
