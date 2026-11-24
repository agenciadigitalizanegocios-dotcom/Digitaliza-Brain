<?php

namespace App\Brain;

use App\Fields\FieldTypes;
use App\Fields\ImageSanitizer;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            $tenantId = app(CurrentTenant::class)->id() ?? 0;
            $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $ext = $ext === 'jpeg' ? 'jpg' : $ext;
            $disk = Storage::disk('public');
            $dir = "tenants/{$tenantId}";

            // Regrava sem EXIF (GPS), com rotação certa e tamanho razoável. Sem GD, usa o original.
            [$binary] = ImageSanitizer::clean($file, $ext) ?? [file_get_contents($file->getRealPath())];

            // Cota de espaço por cliente.
            $quota = (float) config('cerebro.limits.images_mb', 200) * 1024 * 1024;
            if ($quota > 0) {
                $used = array_sum(array_map(fn ($f) => $disk->size($f), $disk->files($dir)));
                if ($used + strlen($binary) > $quota) {
                    throw ValidationException::withMessages(['fields.'.$field['key'] => sprintf(
                        'O espaço de imagens deste cliente acabou (limite de %s MB). Remova imagens que não usa mais.',
                        rtrim(rtrim(number_format($quota / 1024 / 1024, 1, ',', ''), '0'), ',')
                    )]);
                }
            }

            // Nome aleatório: a URL é pública (o n8n precisa dela para mandar a foto no WhatsApp).
            $path = $dir.'/'.Str::random(40).'.'.$ext;
            $disk->put($path, $binary);

            return $path;
        }

        if ($request->boolean('remove.'.$field['key'])) {
            return null; // o arquivo fica: o histórico ainda pode apontar para ele (ver cerebro:limpar-imagens)
        }

        return $current;
    }
}
