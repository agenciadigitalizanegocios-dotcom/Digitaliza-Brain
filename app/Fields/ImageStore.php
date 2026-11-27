<?php

namespace App\Fields;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Grava uma imagem enviada: limpa (sem EXIF/GPS, rotação certa, tamanho razoável), confere a cota
 * do cliente e salva com nome aleatório em tenants/{id}/. Usado pelos campos de imagem e pela galeria.
 */
final class ImageStore
{
    public static function store(UploadedFile $file, int $tenantId, string $errorKey): string
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $disk = Storage::disk('public');
        $dir = "tenants/{$tenantId}";

        [$binary] = ImageSanitizer::clean($file, $ext) ?? [file_get_contents($file->getRealPath())];

        self::assertQuota($tenantId, strlen($binary), $errorKey);

        // Nome aleatório: a URL é pública (vitrine e WhatsApp).
        $path = $dir.'/'.Str::random(40).'.'.$ext;
        $disk->put($path, $binary);

        return $path;
    }

    /** Grava bytes já baixados (importação). Mesmas regras: só jpg/png/webp, limpeza e cota. */
    public static function storeBinary(string $binary, int $tenantId): ?string
    {
        $info = @getimagesizefromstring($binary);
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if (! $ext || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $binary);
        try {
            $file = new UploadedFile($tmp, 'import.'.$ext, $info['mime'], null, true);

            return self::store($file, $tenantId, 'importacao');
        } finally {
            @unlink($tmp);
        }
    }

    public static function assertQuota(int $tenantId, int $newBytes, string $errorKey): void
    {
        $quota = (float) config('cerebro.limits.images_mb', 200) * 1024 * 1024;
        if ($quota <= 0) {
            return;
        }
        $disk = Storage::disk('public');
        $used = array_sum(array_map(fn ($f) => $disk->size($f), $disk->files("tenants/{$tenantId}")));
        if ($used + $newBytes > $quota) {
            throw ValidationException::withMessages([$errorKey => sprintf(
                'O espaço de imagens deste cliente acabou (limite de %s MB). Remova imagens que não usa mais.',
                rtrim(rtrim(number_format($quota / 1024 / 1024, 1, ',', ''), '0'), ',')
            )]);
        }
    }
}
