<?php

namespace App\Brain;

use App\Fields\FieldTypes;
use Illuminate\Support\Facades\Storage;

/** Converte o valor guardado no que o formulário mostra (dinheiro "1.234,56", imagem → URL da prévia). */
final class FormValues
{
    public static function one(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'money' => FieldTypes::moneyInput($value),
            'image' => $value ? Storage::disk('public')->url($value) : null,
            default => $value,
        };
    }

    public static function forForm(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f['key'], $data)) {
                $out[$f['key']] = self::one($f, $data[$f['key']]);
            }
        }

        return $out;
    }
}
