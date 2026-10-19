<?php

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRMarkupSVG;

class QrCodeService
{
    /**
     * Render inline SVG string for direct blade output.
     */
    public function renderSvg(string $url, int $size = 180): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgUseFill' => true,
            'drawLightModules' => false,
            'scale' => 6,
            'addQuietzone' => true,
        ]);

        $svg = (new QRCode($options))->render($url);

        // Ensure width, height and responsive attributes
        if (! str_contains($svg, 'width=')) {
            $svg = str_replace('<svg ', '<svg width="' . $size . '" height="' . $size . '" ', $svg);
        }

        return $svg;
    }


    /**
     * Return a boolean QR matrix for server-side PDF drawing.
     *
     * @return array<int,array<int,bool>>
     */
    public function booleanMatrix(string $url): array
    {
        $options = new QROptions([
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))
            ->addByteSegment($url)
            ->getQRMatrix()
            ->getBooleanMatrix();
    }

    /**
     * Render SVG as downloadable string.
     */
    public function downloadSvg(string $url): string
    {
        return $this->renderSvg($url, 300);
    }
}
