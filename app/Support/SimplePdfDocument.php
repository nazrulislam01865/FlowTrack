<?php

namespace App\Support;

final class SimplePdfDocument
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    /** @var array<int, string> */
    private array $pages = [];
    private string $current = '';

    /** @var array<int, array{name:string,width:int,height:int,color_space:string,bits:int,filter:string,data:string,decode:?string,smask:?string}> */
    private array $images = [];

    /** @var array<string, int> */
    private array $imageKeys = [];

    public function newPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0.12, 0.16, 0.22]): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->current .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $x,
            $y,
            $this->escape($text)
        );
    }

    public function textRight(float $rightX, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0.12, 0.16, 0.22]): void
    {
        $this->text(max(0, $rightX - $this->approxTextWidth($text, $size, $bold)), $y, $text, $size, $bold, $rgb);
    }

    public function textCentered(float $centerX, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0.12, 0.16, 0.22]): void
    {
        $this->text(max(0, $centerX - ($this->approxTextWidth($text, $size, $bold) / 2)), $y, $text, $size, $bold, $rgb);
    }

    /** @return array{lines:int,bottom:float} */
    public function wrappedText(float $x, float $y, string $text, float $width, float $size = 10, float $leading = 13, bool $bold = false, array $rgb = [0.12, 0.16, 0.22], ?int $maxLines = null): array
    {
        $lines = $this->wrap($text, $width, $size);
        if ($maxLines !== null && count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = array_pop($lines) ?? '';
            $lines[] = rtrim(substr($last, 0, max(0, strlen($last) - 3))).'...';
        }
        foreach ($lines as $line) {
            $this->text($x, $y, $line, $size, $bold, $rgb);
            $y -= $leading;
        }
        return ['lines' => count($lines), 'bottom' => $y];
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.7, array $rgb = [0.85, 0.88, 0.92]): void
    {
        $this->current .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $width, $x1, $y1, $x2, $y2);
    }

    public function rect(float $x, float $y, float $width, float $height, float $lineWidth = 0.7, array $rgb = [0.85, 0.88, 0.92]): void
    {
        $this->current .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", $rgb[0], $rgb[1], $rgb[2], $lineWidth, $x, $y, $width, $height);
    }

    public function fillRect(float $x, float $y, float $width, float $height, array $rgb): void
    {
        $this->current .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $width, $height);
    }

    /**
     * Draw a raster image. JPEG is embedded directly. PNG is decoded without
     * GD/Imagick so transparent branding logos also work on minimal servers.
     * WebP is supported when PHP GD is available; otherwise callers can fall
     * back to text branding without breaking PDF generation.
     */
    public function image(string $path, float $x, float $y, float $width, ?float $height = null): bool
    {
        if (!is_file($path) || !is_readable($path) || $width <= 0) {
            return false;
        }

        $realPath = realpath($path) ?: $path;
        $key = $realPath.'|'.((string) @filemtime($path));
        $index = $this->imageKeys[$key] ?? null;

        if ($index === null) {
            $image = $this->readImage($path);
            if ($image === null) {
                return false;
            }
            $index = count($this->images);
            $image['name'] = 'Im'.($index + 1);
            $this->images[] = $image;
            $this->imageKeys[$key] = $index;
        }

        $image = $this->images[$index];
        if ($height === null || $height <= 0) {
            $height = $width * ($image['height'] / max(1, $image['width']));
        }

        $this->current .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $width,
            $height,
            $x,
            $y,
            $image['name']
        );

        return true;
    }

    public function output(): string
    {
        if ($this->current !== '' || $this->pages === []) {
            $this->pages[] = $this->current;
            $this->current = '';
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageCount = count($this->pages);
        $pageObjectNumbers = [];
        $contentObjectNumbers = [];
        $next = 5;
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjectNumbers[] = $next++;
            $contentObjectNumbers[] = $next++;
        }

        $imageObjectNumbers = [];
        $alphaObjectNumbers = [];
        foreach ($this->images as $index => $image) {
            $imageObjectNumbers[$index] = $next++;
            if ($image['smask'] !== null) {
                $alphaObjectNumbers[$index] = $next++;
            }
        }

        $kids = implode(' ', array_map(fn (int $n): string => $n.' 0 R', $pageObjectNumbers));
        $objects[2] = '<< /Type /Pages /Kids ['.$kids.'] /Count '.$pageCount.' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $xObjects = '';
        foreach ($this->images as $index => $image) {
            $xObjects .= '/'.$image['name'].' '.$imageObjectNumbers[$index].' 0 R ';
        }
        $xObjectResource = $xObjects === '' ? '' : ' /XObject << '.trim($xObjects).' >>';

        foreach ($this->pages as $index => $content) {
            $pageNo = $pageObjectNumbers[$index];
            $contentNo = $contentObjectNumbers[$index];
            $objects[$pageNo] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $xObjectResource,
                $contentNo
            );
            $objects[$contentNo] = "<< /Length ".strlen($content)." >>\nstream\n".$content."endstream";
        }

        foreach ($this->images as $index => $image) {
            $imageNo = $imageObjectNumbers[$index];
            $smask = isset($alphaObjectNumbers[$index]) ? ' /SMask '.$alphaObjectNumbers[$index].' 0 R' : '';
            $decode = $image['decode'] ? ' /Decode '.$image['decode'] : '';
            $objects[$imageNo] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Filter /%s%s%s /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['color_space'],
                $image['bits'],
                $image['filter'],
                $decode,
                $smask,
                strlen($image['data']),
                $image['data']
            );

            if (isset($alphaObjectNumbers[$index]) && $image['smask'] !== null) {
                $alpha = $image['smask'];
                $objects[$alphaObjectNumbers[$index]] = sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                    $image['width'],
                    $image['height'],
                    strlen($alpha),
                    $alpha
                );
            }
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    /** @return list<string> */
    private function wrap(string $text, float $width, float $size): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') return [''];

        $maxChars = max(8, (int) floor($width / max(1, $size * 0.52)));
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if (strlen($word) > $maxChars) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                while (strlen($word) > $maxChars) {
                    $lines[] = substr($word, 0, $maxChars);
                    $word = substr($word, $maxChars);
                }
                $line = $word;
                continue;
            }
            $candidate = $line === '' ? $word : $line.' '.$word;
            if (strlen($candidate) > $maxChars) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }

    private function escape(string $text): string
    {
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? $text;
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) $text = $converted;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function approxTextWidth(string $text, float $size, bool $bold): float
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        $length = strlen($converted !== false ? $converted : $text);
        return $length * $size * ($bold ? 0.56 : 0.52);
    }

    /** @return array{name:string,width:int,height:int,color_space:string,bits:int,filter:string,data:string,decode:?string,smask:?string}|null */
    private function readImage(string $path): ?array
    {
        $info = @getimagesize($path);
        if (!$info) return null;

        $mime = strtolower((string) ($info['mime'] ?? ''));
        if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            $data = @file_get_contents($path);
            if ($data === false) return null;
            $channels = (int) ($info['channels'] ?? 3);
            return [
                'name' => '',
                'width' => (int) $info[0],
                'height' => (int) $info[1],
                'color_space' => $channels === 1 ? 'DeviceGray' : ($channels === 4 ? 'DeviceCMYK' : 'DeviceRGB'),
                'bits' => (int) ($info['bits'] ?? 8),
                'filter' => 'DCTDecode',
                'data' => $data,
                'decode' => $channels === 4 ? '[1 0 1 0 1 0 1 0]' : null,
                'smask' => null,
            ];
        }

        if ($mime === 'image/png') {
            return $this->readPng($path);
        }

        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
            $image = @imagecreatefromwebp($path);
            if ($image === false) return null;
            $tmp = tempnam(sys_get_temp_dir(), 'flowtrack-logo-');
            if ($tmp === false) {
                imagedestroy($image);
                return null;
            }
            @imagealphablending($image, false);
            @imagesavealpha($image, true);
            $ok = @imagepng($image, $tmp);
            imagedestroy($image);
            $result = $ok ? $this->readPng($tmp) : null;
            @unlink($tmp);
            return $result;
        }

        return null;
    }

    /** @return array{name:string,width:int,height:int,color_space:string,bits:int,filter:string,data:string,decode:?string,smask:?string}|null */
    private function readPng(string $path): ?array
    {
        $png = @file_get_contents($path);
        if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

        $offset = 8;
        $width = $height = $bitDepth = $colorType = $interlace = null;
        $idat = '';
        $palette = null;
        $transparency = null;
        $length = strlen($png);

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $chunk = substr($png, $offset + 8, $chunkLength);
            $offset += 12 + $chunkLength;

            if ($type === 'IHDR' && strlen($chunk) >= 13) {
                $header = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk);
                $width = (int) $header['width'];
                $height = (int) $header['height'];
                $bitDepth = (int) $header['bit'];
                $colorType = (int) $header['color'];
                $interlace = (int) $header['interlace'];
            } elseif ($type === 'PLTE') {
                $palette = $chunk;
            } elseif ($type === 'tRNS') {
                $transparency = $chunk;
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if (!$width || !$height || $bitDepth !== 8 || $interlace !== 0 || $idat === '') return null;
        $bytesPerPixel = match ($colorType) {
            0 => 1,
            2 => 3,
            3 => 1,
            4 => 2,
            6 => 4,
            default => 0,
        };
        if ($bytesPerPixel === 0) return null;

        $raw = @zlib_decode($idat);
        if ($raw === false) return null;
        $rowLength = $width * $bytesPerPixel;
        $expected = ($rowLength + 1) * $height;
        if (strlen($raw) < $expected) return null;

        $rgb = '';
        $alpha = '';
        $previous = array_fill(0, $rowLength, 0);
        $cursor = 0;
        $hasTransparency = false;

        $transparentGray = null;
        $transparentRgb = null;
        if ($colorType === 0 && is_string($transparency) && strlen($transparency) >= 2) {
            $transparentGray = unpack('n', substr($transparency, 0, 2))[1];
        } elseif ($colorType === 2 && is_string($transparency) && strlen($transparency) >= 6) {
            $transparentRgb = array_values(unpack('nr/ng/nb', substr($transparency, 0, 6)));
        }

        for ($row = 0; $row < $height; $row++) {
            $filter = ord($raw[$cursor++]);
            $scan = array_values(unpack('C*', substr($raw, $cursor, $rowLength)) ?: []);
            $cursor += $rowLength;
            $decoded = [];

            for ($i = 0; $i < $rowLength; $i++) {
                $value = $scan[$i] ?? 0;
                $left = $i >= $bytesPerPixel ? $decoded[$i - $bytesPerPixel] : 0;
                $up = $previous[$i] ?? 0;
                $upLeft = $i >= $bytesPerPixel ? ($previous[$i - $bytesPerPixel] ?? 0) : 0;
                $decoded[$i] = match ($filter) {
                    0 => $value,
                    1 => ($value + $left) & 0xff,
                    2 => ($value + $up) & 0xff,
                    3 => ($value + intdiv($left + $up, 2)) & 0xff,
                    4 => ($value + $this->paeth($left, $up, $upLeft)) & 0xff,
                    default => -1,
                };
                if ($decoded[$i] < 0) return null;
            }

            for ($x = 0; $x < $width; $x++) {
                $base = $x * $bytesPerPixel;
                $r = $g = $b = 0;
                $a = 255;

                if ($colorType === 0) {
                    $r = $g = $b = $decoded[$base];
                    if ($transparentGray !== null && $decoded[$base] === $transparentGray) $a = 0;
                } elseif ($colorType === 2) {
                    $r = $decoded[$base]; $g = $decoded[$base + 1]; $b = $decoded[$base + 2];
                    if ($transparentRgb !== null && $r === $transparentRgb[0] && $g === $transparentRgb[1] && $b === $transparentRgb[2]) $a = 0;
                } elseif ($colorType === 3) {
                    if (!is_string($palette)) return null;
                    $index = $decoded[$base];
                    $paletteOffset = $index * 3;
                    if ($paletteOffset + 2 >= strlen($palette)) return null;
                    $r = ord($palette[$paletteOffset]);
                    $g = ord($palette[$paletteOffset + 1]);
                    $b = ord($palette[$paletteOffset + 2]);
                    if (is_string($transparency) && $index < strlen($transparency)) $a = ord($transparency[$index]);
                } elseif ($colorType === 4) {
                    $r = $g = $b = $decoded[$base];
                    $a = $decoded[$base + 1];
                } elseif ($colorType === 6) {
                    $r = $decoded[$base]; $g = $decoded[$base + 1]; $b = $decoded[$base + 2]; $a = $decoded[$base + 3];
                }

                $rgb .= chr($r).chr($g).chr($b);
                $alpha .= chr($a);
                if ($a !== 255) $hasTransparency = true;
            }

            $previous = $decoded;
        }

        $compressedRgb = @gzcompress($rgb, 6);
        if ($compressedRgb === false) return null;
        $compressedAlpha = null;
        if ($hasTransparency) {
            $compressedAlpha = @gzcompress($alpha, 6);
            if ($compressedAlpha === false) return null;
        }

        return [
            'name' => '',
            'width' => $width,
            'height' => $height,
            'color_space' => 'DeviceRGB',
            'bits' => 8,
            'filter' => 'FlateDecode',
            'data' => $compressedRgb,
            'decode' => null,
            'smask' => $compressedAlpha,
        ];
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) return $a;
        if ($pb <= $pc) return $b;
        return $c;
    }
}
