<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Prepara logos de e-mail: fundo branco opaco e resolução adequada para clientes de e-mail.
 * Evita PNG transparente virar “tarja preta” no modo escuro (Gmail/Outlook/Apple Mail).
 */
final class EmailLogoImage
{
    /** Largura máxima em pixels (≈2× a largura de exibição no e-mail). */
    public const MAX_WIDTH = 1120;

    /** Altura máxima em pixels. */
    public const MAX_HEIGHT = 480;

    /**
     * @return array{0: string, 1: string} Conteúdo binário e extensão (png|jpg)
     */
    public static function prepareForEmail(UploadedFile $file): array
    {
        $originalExt = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png'));
        if (! in_array($originalExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $originalExt = 'png';
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecreatetruecolor')) {
            $raw = @file_get_contents($file->getRealPath() ?: $file->getPathname());
            if (! is_string($raw) || $raw === '') {
                throw new \RuntimeException('Não foi possível ler a imagem enviada.');
            }

            return [$raw, $originalExt === 'jpeg' ? 'jpg' : $originalExt];
        }

        $contents = @file_get_contents($file->getRealPath() ?: $file->getPathname());
        if (! is_string($contents) || $contents === '') {
            throw new \RuntimeException('Não foi possível ler a imagem enviada.');
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new \RuntimeException('A imagem está corrompida ou é inválida. Use PNG, JPG ou WebP.');
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($source);
            throw new \RuntimeException('A imagem enviada é inválida.');
        }

        $dstW = $srcW;
        $dstH = $srcH;
        if ($dstW > self::MAX_WIDTH || $dstH > self::MAX_HEIGHT) {
            $scale = min(self::MAX_WIDTH / $dstW, self::MAX_HEIGHT / $dstH);
            $dstW = max(1, (int) round($dstW * $scale));
            $dstH = max(1, (int) round($dstH * $scale));
        }

        $canvas = imagecreatetruecolor($dstW, $dstH);
        if ($canvas === false) {
            imagedestroy($source);
            throw new \RuntimeException('Falha ao processar a logo.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);

        ob_start();
        imagepng($canvas, null, 6);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        if ($binary === '') {
            throw new \RuntimeException('Falha ao gerar a logo para e-mail.');
        }

        return [$binary, 'png'];
    }
}
