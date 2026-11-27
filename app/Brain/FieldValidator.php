<?php

namespace App\Brain;

use App\Fields\FieldTypes;
use App\Fields\ImageStore;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Valida os campos (ativos) de um formulário e devolve os valores limpos.
 * Campos arquivados não são validados nem devolvidos: quem chama faz merge com o valor antigo.
 * Genérico: o Catálogo (Parte 2) usa igual.
 */
final class FieldValidator
{
    /**
     * @param  array  $fields  definições dos campos ativos
     * @param  array  $existing  valores atuais (para manter a imagem quando não vier arquivo novo)
     */
    public static function validate(Request $request, array $fields, array $existing = []): array
    {
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $name = 'fields.'.$field['key'];
            $attributes[$name] = mb_strtolower($field['label']);

            if ($field['type'] === 'image') {
                $rules[$name] = FieldTypes::imageRules();
            } else {
                $rules[$name] = FieldTypes::rules($field);
            }
        }

        $request->validate($rules, [], $attributes);

        $values = [];
        $missingImages = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $name = 'fields.'.$key;

            $values[$key] = match ($field['type']) {
                'checkbox' => $request->boolean($name),
                'number' => $request->filled($name) ? (int) $request->input($name) : null,
                'money' => $request->filled($name) ? FieldTypes::parseMoney((string) $request->input($name)) : null,
                'image' => self::image($request, $field, $existing[$key] ?? null),
                default => trim((string) $request->input($name, '')),
            };

            if ($field['type'] === 'image' && ($field['required'] ?? false) && ! $values[$key]) {
                $missingImages[$name] = "Envie uma imagem em ".mb_strtolower($field['label']).'.';
            }
        }

        if ($missingImages) {
            throw ValidationException::withMessages($missingImages);
        }

        return $values;
    }

    /** Arquivo novo → grava e devolve o caminho; "remover" → null; nada → mantém o atual. */
    private static function image(Request $request, array $field, ?string $current): ?string
    {
        $file = $request->file('fields.'.$field['key']);

        if ($file) {
            // Limpa (sem EXIF/GPS), confere a cota e grava com nome aleatório.
            return ImageStore::store($file, app(CurrentTenant::class)->id() ?? 0, 'fields.'.$field['key']);
        }

        if ($request->boolean('remove.'.$field['key'])) {
            return null; // o arquivo fica: o histórico ainda pode apontar para ele (ver cerebro:limpar-imagens)
        }

        return $current;
    }
}
