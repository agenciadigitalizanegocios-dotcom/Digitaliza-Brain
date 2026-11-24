<?php

namespace App\Fields;

use InvalidArgumentException;

/**
 * Registro central dos tipos de campo.
 * Usado pelas abas do cérebro e, na Parte 2, pelo Catálogo de produtos.
 * Cada tipo sabe: validar, mostrar no cartão, virar texto para a IA e sair na API.
 */
final class FieldTypes
{
    public const TYPES = [
        'text' => 'Texto curto',
        'textarea' => 'Texto longo',
        'url' => 'Link',
        'number' => 'Número',
        'money' => 'Dinheiro (R$)',
        'select' => 'Lista de opções',
        'checkbox' => 'Sim / não',
        'image' => 'Imagem',
    ];

    /** Maior valor aceito em dinheiro: R$ 99.999.999,99. */
    public const MONEY_MAX = 9_999_999_999;

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** Regras de validação do Laravel para o valor do campo (no nome "fields.{key}"). */
    public static function rules(array $field): array
    {
        $required = ($field['required'] ?? false) ? 'required' : 'nullable';

        return match ($field['type']) {
            'number' => array_values(array_filter([
                $required,
                'integer',
                isset($field['min']) && $field['min'] !== null ? 'min:'.$field['min'] : null,
                isset($field['max']) && $field['max'] !== null ? 'max:'.$field['max'] : null,
            ])),
            'select' => [$required, 'in:'.implode(',', array_keys(self::activeOptions($field) + ($field['options'] ?? [])))],
            'checkbox' => ['nullable', 'boolean'],
            'url' => [$required, 'url', 'max:255'],
            'textarea' => [$required, 'string', 'max:5000'],
            'money' => [$required, 'string', 'max:30', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                try {
                    self::parseMoney((string) $value);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
            // A imagem é validada à parte (arquivo); aqui só o "obrigatório" quando não há imagem.
            'image' => ['nullable'],
            default => [$required, 'string', 'max:500'],
        };
    }

    /** Regras do arquivo de imagem. */
    public static function imageRules(): array
    {
        $px = (int) config('cerebro.limits.image_max_px', 6000);

        return ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120',
            "dimensions:max_width={$px},max_height={$px}"];
    }

    /** Opções ativas do select (as arquivadas somem do formulário, mas continuam valendo nos itens antigos). */
    public static function activeOptions(array $field): array
    {
        $archived = $field['archived_options'] ?? [];

        return array_filter($field['options'] ?? [], fn ($k) => ! in_array($k, $archived, true), ARRAY_FILTER_USE_KEY);
    }

    /** Texto do valor no cartão. */
    public static function display(array $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field['type']) {
            'select' => (string) ($field['options'][$value] ?? $value),
            'checkbox' => $value ? 'Sim' : 'Não',
            'money' => is_numeric($value) ? self::formatMoney((int) $value) : (string) $value,
            'image' => '',
            default => trim((string) $value),
        };
    }

    /** Texto do valor para a IA. Imagem nunca vai para a IA. */
    public static function aiText(array $field, mixed $value): string
    {
        if ($field['type'] === 'image') {
            return '';
        }

        return self::display($field, $value);
    }

    /** Valor na API do n8n. */
    public static function apiValue(array $field, mixed $value, ?callable $imageUrl = null): mixed
    {
        if ($field['type'] === 'image') {
            if (! $value) {
                return null;
            }

            return $imageUrl ? $imageUrl($value) : $value;
        }

        if ($field['type'] === 'money') {
            return $value === null || $value === '' ? null : (int) $value;
        }

        return $value;
    }

    /**
     * Converte o que foi digitado em centavos.
     *   "1.234,56" → 123456 · "1234,5" → 123450 · "12.50" → 1250 · "1.234" → 123400 · "1234" → 123400
     * Recusa negativo, mais de 2 casas, letras, formato misturado ("1,234.56") e acima de R$ 99.999.999,99.
     */
    public static function parseMoney(string $input): ?int
    {
        $s = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $input));

        if ($s === '') {
            return null;
        }
        if (str_starts_with($s, '-')) {
            throw new InvalidArgumentException('O valor não pode ser negativo.');
        }

        if (str_contains($s, ',')) {
            // Vírgula = decimal; pontos = milhar.
            if (! preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/', $s)) {
                throw new InvalidArgumentException(
                    preg_match('/,\d{3,}$/', $s)
                        ? 'Use no máximo 2 casas depois da vírgula (ex.: 1.234,56).'
                        : 'Valor inválido. Use o formato 1.234,56.'
                );
            }
            [$int, $dec] = explode(',', $s);
            $cents = (int) str_replace('.', '', $int) * 100 + (int) str_pad($dec, 2, '0');
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $s)) {
            // Um ponto seguido de 1 ou 2 dígitos no fim: decimal.
            [$int, $dec] = explode('.', $s);
            $cents = (int) $int * 100 + (int) str_pad($dec, 2, '0');
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            // Pontos separando grupos de 3: milhar.
            $cents = (int) str_replace('.', '', $s) * 100;
        } elseif (preg_match('/^\d+$/', $s)) {
            $cents = (int) $s * 100;
        } else {
            throw new InvalidArgumentException('Valor inválido. Use o formato 1.234,56.');
        }

        if ($cents > self::MONEY_MAX) {
            throw new InvalidArgumentException('O valor máximo é R$ 99.999.999,99.');
        }

        return $cents;
    }

    public static function formatMoney(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    /** Valor do campo de dinheiro para mostrar no input ("1.234,56"). */
    public static function moneyInput(mixed $cents): string
    {
        return is_numeric($cents) ? number_format(((int) $cents) / 100, 2, ',', '.') : (string) ($cents ?? '');
    }
}
