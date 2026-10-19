<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Validator;

/**
 * Single source of truth for FlowTrack business-document attachments.
 *
 * Keep purpose-specific image inputs (profile photos, logos, product images)
 * and structured imports (bulk-order spreadsheets) on their own validators.
 * Every normal attachment/document uploader should use BUSINESS_DOCUMENTS.
 */
final class AttachmentUpload
{
    /** Existing business-document ceiling outside the Artwork phase. */
    public const STANDARD_MAX_KILOBYTES = 20480;

    /** Artwork source/revision files are intentionally allowed up to 400MB. */
    public const ARTWORK_MAX_KILOBYTES = 409600;

    public const ARTWORK_MAX_BYTES = self::ARTWORK_MAX_KILOBYTES * 1024;

    /**
     * Business attachment formats supported throughout FlowTrack.
     *
     * @var array<int,string>
     */
    public const BUSINESS_DOCUMENTS = [
        'pdf',
        'doc', 'docx',
        'xls', 'xlsx',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'ico',
        'zip',
        'txt', 'csv',
        'ai', 'eps', 'esp', 'cdr',
    ];

    /**
     * Formats whose MIME reporting is not reliable enough for Laravel's
     * `mimes` rule. They are still checked by SecureDocumentStorage and
     * UploadSecurityService before becoming normal business documents.
     */
    public const MIME_FLEXIBLE = ['ai', 'eps', 'esp', 'cdr'];

    /** @deprecated Kept for compatibility with older callers/tests. */
    public const VECTOR = ['eps', 'esp'];

    // Existing public constants remain available so older feature code does
    // not need to know that attachment support is now centralized.
    public const DOCUMENTS = self::BUSINESS_DOCUMENTS;
    public const DOCUMENTS_WITH_AI = self::BUSINESS_DOCUMENTS;
    public const ORDER_REQUIRED = self::BUSINESS_DOCUMENTS;
    public const FINANCE = self::BUSINESS_DOCUMENTS;
    public const PRODUCT_SUPPORTING = self::BUSINESS_DOCUMENTS;

    public static function requiredRules(array $extensions = self::BUSINESS_DOCUMENTS, int $maxKilobytes = 20480): array
    {
        return ['required', 'file', 'max:'.$maxKilobytes, self::typeRule($extensions)];
    }

    public static function nullableRules(array $extensions = self::BUSINESS_DOCUMENTS, int $maxKilobytes = 20480): array
    {
        return ['nullable', 'file', 'max:'.$maxKilobytes, self::typeRule($extensions)];
    }

    public static function itemRules(array $extensions = self::BUSINESS_DOCUMENTS, int $maxKilobytes = 20480): array
    {
        return ['file', 'max:'.$maxKilobytes, self::typeRule($extensions)];
    }

    public static function extensions(array $extensions = self::BUSINESS_DOCUMENTS): array
    {
        $normalized = array_map(
            static fn (mixed $extension): string => strtolower(trim((string) $extension)),
            $extensions,
        );

        return array_values(array_unique(array_filter($normalized)));
    }

    public static function accept(array $extensions = self::BUSINESS_DOCUMENTS): string
    {
        return implode(',', array_map(static fn (string $extension): string => '.'.$extension, self::extensions($extensions)));
    }

    public static function helperText(int $maxMegabytes = 20): string
    {
        return 'PDF, Word, Excel, JPG, PNG, WEBP, GIF, ICO, ZIP, AI, EPS/ESP, CDR, TXT or CSV · Max '.$maxMegabytes.' MB per file';
    }

    public static function validationMessage(array $extensions = self::BUSINESS_DOCUMENTS): string
    {
        $labels = [];
        foreach (self::extensions($extensions) as $extension) {
            $label = match ($extension) {
                'jpeg' => 'JPG',
                'doc', 'docx' => 'Word',
                'xls', 'xlsx' => 'Excel',
                default => strtoupper($extension),
            };
            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        if (count($labels) === 1) {
            return 'Unsupported file type. Use '.$labels[0].'.';
        }

        $last = array_pop($labels);

        return 'Unsupported file type. Use '.implode(', ', $labels).' or '.$last.'.';
    }

    private static function typeRule(array $extensions): Closure
    {
        $allowedExtensions = self::extensions($extensions);
        $standardExtensions = array_values(array_diff($allowedExtensions, self::MIME_FLEXIBLE));
        $message = self::validationMessage($extensions);

        return static function (string $attribute, mixed $value, Closure $fail) use ($standardExtensions, $allowedExtensions, $message): void {
            if (! is_object($value) || ! method_exists($value, 'getClientOriginalExtension')) {
                return; // The normal `file` rule reports invalid upload values.
            }

            $extension = strtolower(trim((string) $value->getClientOriginalExtension()));
            if (! in_array($extension, $allowedExtensions, true)) {
                $fail($message);
                return;
            }

            // Illustrator, EPS/ESP and CorelDRAW are intentionally validated
            // by original extension here because their browser/Fileinfo MIME
            // values vary widely. UploadSecurityService still scans the bytes,
            // blocks executable/script signatures and runs ClamAV when enabled.
            if (in_array($extension, self::MIME_FLEXIBLE, true)) {
                return;
            }

            if ($standardExtensions === []) {
                return;
            }

            $validator = Validator::make(
                ['upload' => $value],
                ['upload' => ['mimes:'.implode(',', $standardExtensions)]],
            );

            if ($validator->fails()) {
                $fail($message);
            }
        };
    }
}
