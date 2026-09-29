<?php

declare(strict_types=1);

namespace App\Services;

use GdImage;
use InvalidArgumentException;

final class LogoProcessor
{
    public const HEIGHT = 300;

    public const MAX_WIDTH = 900;

    public static function process(
        string $bytes,
        string $aspect = 'auto',
        float $zoom = 1,
        float $offsetX = 0,
        float $offsetY = 0,
    ): string {
        $info = getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new InvalidArgumentException('El logo debe ser PNG o JPG.');
        }
        if (! in_array($aspect, ['auto', 'square'], true)) {
            throw new InvalidArgumentException('Encuadre no válido.');
        }

        $source = imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('No se pudo procesar la imagen.');
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $ratio = $aspect === 'square' ? 1 : min(self::MAX_WIDTH / self::HEIGHT, max(1, $sourceWidth / $sourceHeight));
            $canvasWidth = (int) round(self::HEIGHT * $ratio);
            $canvasHeight = self::HEIGHT;
            $scale = ($aspect === 'square'
                ? max($canvasWidth / $sourceWidth, $canvasHeight / $sourceHeight)
                : min($canvasWidth / $sourceWidth, $canvasHeight / $sourceHeight)) * $zoom;
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            $x = (int) round(($canvasWidth - $width) / 2 - $offsetX * max(0, $width - $canvasWidth) / 2);
            $y = (int) round(($canvasHeight - $height) / 2 - $offsetY * max(0, $height - $canvasHeight) / 2);

            $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagecopyresampled($canvas, $source, $x, $y, 0, 0,
                $width, $height, $sourceWidth, $sourceHeight);

            ob_start();
            imagepng($canvas, null, 6);
            $png = ob_get_clean();
            imagedestroy($canvas);

            if (! is_string($png) || $png === '') {
                throw new InvalidArgumentException('No se pudo guardar el logo.');
            }

            return $png;
        } finally {
            imagedestroy($source);
        }
    }
}
