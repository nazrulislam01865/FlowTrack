<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class RichTextService
{
    public const MARKER = '<!--flowtrack-rich-text-->';

    private const MAX_STORED_LENGTH = 60000;

    public function isRich(?string $value): bool
    {
        return str_starts_with(ltrim((string) $value), self::MARKER);
    }

    public function normalize(?string $value, int $maxTextCharacters = 10000, string $field = 'content'): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        if ($this->isRich($value)) {
            $html = trim(substr(ltrim($value), strlen(self::MARKER)));
            $html = $this->sanitizeHtml($html);

            if (!$this->htmlHasContent($html)) return null;

            $normalized = self::MARKER.$html;
            $this->assertWithinLimits($normalized, $maxTextCharacters, $field);
            return $normalized;
        }

        $this->assertWithinLimits($value, $maxTextCharacters, $field);
        return $value;
    }

    public function hasContent(?string $value): bool
    {
        $value = (string) $value;
        if ($this->isRich($value)) {
            $html = $this->sanitizeHtml(substr(ltrim($value), strlen(self::MARKER)));
            return $this->htmlHasContent($html);
        }

        return trim($value) !== '';
    }

    public function plainText(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') return '';

        if ($this->isRich($value)) {
            $html = $this->sanitizeHtml(substr(ltrim($value), strlen(self::MARKER)));
            $html = preg_replace('/<img\b[^>]*>/i', ' [Image] ', $html) ?? $html;
            $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
            $html = preg_replace('/<\/(p|li)>/i', "\n", $html) ?? $html;
            $html = preg_replace('/<li>/i', '• ', $html) ?? $html;
            $text = strip_tags($html);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace("\xC2\xA0", ' ', $text);
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
            return trim($text);
        }

        return trim($value);
    }

    public function safeHtml(?string $value): ?string
    {
        if (!$this->isRich($value)) return null;

        // Rendering is the only place where physical existence matters. Keep
        // the stored source intact, but suppress stale image tags so the browser
        // never requests a protected rich-text image that the server cannot find.
        return $this->sanitizeHtml(
            substr(ltrim((string) $value), strlen(self::MARKER)),
            guardStoredImages: true,
        );
    }

    /**
     * Return privately stored inline images as file-style presentation data.
     * This lets compact activity cards reuse the same Open/Download treatment
     * as normal Document attachments instead of forcing a large inline image.
     *
     * @return list<array{name:string,extension:string,url:string,download_url:string}>
     */
    public function imageAttachments(?string $value): array
    {
        $html = $this->safeHtml($value);
        if (!$html) return [];

        preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"[^>]*>/i', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(function (string $src): ?array {
                $path = (string) (parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH) ?: '');
                if (preg_match('#(?:^|/)rich-text-images/([A-Za-z0-9-]+\.(?:png|jpe?g|webp|gif))$#i', $path, $match) !== 1) {
                    return null;
                }

                $filename = $match[1];
                $assets = app(StoredAssetUrlService::class);
                $url = $assets->richTextImageUrl($filename);
                $downloadUrl = $assets->richTextImageDownloadUrl($filename);
                if (! $url || ! $downloadUrl) return null;

                return [
                    'name' => $filename,
                    'extension' => strtoupper(pathinfo($filename, PATHINFO_EXTENSION) ?: 'IMG'),
                    'url' => $url,
                    'download_url' => $downloadUrl,
                ];
            })
            ->filter()
            ->unique('name')
            ->values()
            ->all();
    }

    /** Return the rich content with inline images removed, preserving its text formatting. */
    public function withoutImages(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (!$this->isRich($value)) return $value;

        $html = $this->safeHtml($value) ?? '';
        $html = trim(preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html);

        return $this->htmlHasContent($html) ? self::MARKER.$html : null;
    }

    public function prependText(string $prefix, ?string $value): string
    {
        $prefix = trim($prefix);
        $value = (string) $value;

        if ($this->isRich($value)) {
            $existing = $this->safeHtml($value) ?? '';
            $combined = '<p>'.e($prefix).'</p>'.$existing;
            return self::MARKER.$this->sanitizeHtml($combined);
        }

        return trim($prefix.($value !== '' ? "\n\n".$value : ''));
    }

    private function assertWithinLimits(string $value, int $maxTextCharacters, string $field): void
    {
        if (strlen($value) > self::MAX_STORED_LENGTH) {
            throw ValidationException::withMessages([
                $field => 'This rich text is too large. Remove some text or pasted images and try again.',
            ]);
        }

        $plain = $this->plainText($value);
        if (mb_strlen($plain) > $maxTextCharacters) {
            throw ValidationException::withMessages([
                $field => 'Text must be '.$maxTextCharacters.' characters or fewer.',
            ]);
        }
    }

    private function htmlHasContent(string $html): bool
    {
        if (preg_match('/<img\b/i', $html) === 1) return true;
        return trim($this->plainText(self::MARKER.$html)) !== '';
    }

    private function sanitizeHtml(string $html, bool $guardStoredImages = false): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = strip_tags($html, '<p><div><br><strong><b><em><i><u><ul><ol><li><img>');

        $html = preg_replace_callback('/<img\b[^>]*>/i', function (array $match) use ($guardStoredImages): string {
            $tag = $match[0];
            $src = '';

            if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $quoted)) {
                $src = $quoted[2];
            } elseif (preg_match('/\bsrc\s*=\s*([^\s>]+)/i', $tag, $plain)) {
                $src = trim($plain[1], "\"'");
            }

            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = parse_url($src, PHP_URL_PATH) ?: '';

            // FlowTrack may be served from the web root in production or from
            // a subdirectory locally (for example XAMPP's
            // /laravel/flowtrack/public). route(..., false) correctly includes
            // that base path, so do not require the image URL to begin exactly
            // with /rich-text-images/. Instead, accept only our controlled
            // rich-text image route suffix, extract the safe filename, and
            // regenerate the current application's relative URL.
            if (!preg_match('#(?:^|/)rich-text-images/([A-Za-z0-9-]+\.(?:png|jpe?g|webp|gif))$#i', $path, $imageMatch)) {
                return '';
            }

            $safeUrl = $guardStoredImages
                ? app(StoredAssetUrlService::class)->richTextImageUrl($imageMatch[1])
                : route('rich-text-images.show', ['filename' => $imageMatch[1]], false);

            if (! $safeUrl) return '';

            return '<img src="'.e($safeUrl).'" alt="Pasted image" loading="lazy">';
        }, $html) ?? $html;

        $html = preg_replace_callback('/<(p|div|strong|b|em|i|u|ul|ol|li)\b[^>]*>/i',
            fn (array $match): string => '<'.strtolower($match[1]).'>',
            $html
        ) ?? $html;
        $html = preg_replace('/<br\b[^>]*>/i', '<br>', $html) ?? $html;

        $html = str_ireplace(['<b>', '</b>', '<i>', '</i>', '<div>', '</div>'],
            ['<strong>', '</strong>', '<em>', '</em>', '<p>', '</p>'],
            $html
        );

        return trim($html);
    }
}
