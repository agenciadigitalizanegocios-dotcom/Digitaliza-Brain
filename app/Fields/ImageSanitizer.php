<?php

namespace App\Fields;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Regrava a imagem antes de salvar: tira os metadados (EXIF, que pode ter o GPS de onde a foto
 * foi tirada; a URL é pública), corrige a rotação de foto de celular e reduz fotos gigantes.
 * Precisa da extensão GD. Sem ela, salva o original e registra um aviso.
 */
final class ImageSanitizer
{
    /** Devolve [conteúdo binário, extensão] já limpos, ou null se não deu para reprocessar. */
    public static function clean(UploadedFile $file, string $ext): ?array
    {
        if (! extension_loaded('gd')) {
            Log::warning('Extensão GD ausente: imagem salva sem remover metadados (EXIF).');

            return null;
        }

        $path = $file->getRealPath();
        $image = match ($ext) {
            'jpg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $image) {
            return null;
        }

        // Foto de celular deitada: aplica a rotação do EXIF antes de descartá-lo.
        if ($ext === 'jpg' && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $rotated = imagerotate($image, $angle, 0);
                if ($rotated) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }

        // Reduz se passar do lado máximo.
        $max = (int) config('cerebro.limits.image_resize_px', 2000);
        $w = imagesx($image);
        $h = imagesy($image);
        if ($max > 0 && max($w, $h) > $max) {
            $ratio = $max / max($w, $h);
            $resized = imagescale($image, (int) round($w * $ratio), (int) round($h * $ratio));
            if ($resized) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        if ($ext !== 'jpg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $ok = match ($ext) {
            'jpg' => imagejpeg($image, null, 85),
            'png' => imagepng($image, null, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($image, null, 85) : false,
            default => false,
        };
        $binary = ob_get_clean();
        imagedestroy($image);

        return $ok && $binary !== '' ? [$binary, $ext] : null;
    }
}
