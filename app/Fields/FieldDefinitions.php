<?php

namespace App\Fields;

use App\Brain\StructureValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Criar e editar a DEFINIÇÃO de um campo (nome, tipo, opções...).
 * Compartilhado pelo editor de estrutura do cérebro e pelo editor de tipos de produto.
 */
final class FieldDefinitions
{
    public static function validate(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(FieldTypes::TYPES))],
            'required' => ['nullable', 'boolean'],
            'placeholder' => ['nullable', 'string', 'max:300'],
            'help' => ['nullable', 'string', 'max:300'],
            'options' => ['nullable', 'string', 'max:3000'],
            'archived_options' => ['nullable', 'string', 'max:500'],
            'default' => ['nullable', 'boolean'],
            'min' => ['nullable', 'integer'],
            'max' => ['nullable', 'integer'],
            'send_to_ai' => ['nullable', 'boolean'],
            'public' => ['nullable', 'boolean'],
        ], [], ['label' => 'nome do campo']);
    }

    public static function make(string $key, string $label, string $type, bool $required): array
    {
        return [
            'key' => $key, 'label' => $label, 'type' => $type, 'required' => $required,
            'placeholder' => '', 'help' => '', 'options' => $type === 'select' ? [] : null, 'archived_options' => [],
            'default' => null, 'min' => null, 'max' => null, 'send_to_ai' => $type !== 'image',
            'public' => true, 'archived' => false,
        ];
    }

    /**
     * Opções do select: uma por linha, "chave: Rótulo" ou só "Rótulo" (a chave é gerada).
     * As chaves que já existiam nunca mudam (o validador recusa se sumirem).
     */
    public static function fill(array $field, array $data): array
    {
        $field['label'] = $data['label'];
        $field['type'] = $data['type'];
        $field['required'] = (bool) ($data['required'] ?? false);
        $field['placeholder'] = (string) ($data['placeholder'] ?? '');
        $field['help'] = (string) ($data['help'] ?? '');
        $field['min'] = isset($data['min']) ? (int) $data['min'] : null;
        $field['max'] = isset($data['max']) ? (int) $data['max'] : null;
        $field['send_to_ai'] = $data['type'] === 'image' ? false : (bool) ($data['send_to_ai'] ?? false);
        $field['default'] = $data['type'] === 'checkbox' ? (bool) ($data['default'] ?? false) : null;
        if (array_key_exists('public', $data)) {
            $field['public'] = (bool) $data['public'];
        }

        if ($data['type'] === 'select') {
            $options = $field['options'] ?? [];
            foreach (preg_split('/\R/', (string) ($data['options'] ?? '')) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^([a-z][a-z0-9_]{0,39})\s*:\s*(.+)$/', $line, $m)) {
                    $options[$m[1]] = trim($m[2]);
                } elseif (! in_array($line, $options, true)) {
                    $options[StructureValidator::makeKey($line, array_map('strval', array_keys($options)), 'opcao')] = $line;
                }
            }
            $field['options'] = $options;
            $field['archived_options'] = array_values(array_intersect(
                array_keys($options),
                array_filter(array_map('trim', explode(',', (string) ($data['archived_options'] ?? ''))))
            ));
        } else {
            $field['options'] = null;
            $field['archived_options'] = [];
        }

        return $field;
    }
}
