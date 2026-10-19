<?php

namespace App\Support;

final class CreateOrderDocumentPresenter
{
    /**
     * @return array{name:string, extension:string, size:int, size_label:string, type_label:string, icon_class:string}
     */
    public static function fileMeta(mixed $file): array
    {
        $name = is_object($file) && method_exists($file, 'getClientOriginalName')
            ? (string) $file->getClientOriginalName()
            : 'Selected file';
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $size = is_object($file) && method_exists($file, 'getSize')
            ? (int) ($file->getSize() ?: 0)
            : 0;

        return [
            'name' => $name,
            'extension' => $extension,
            'size' => $size,
            'size_label' => self::sizeLabel($size),
            'type_label' => $extension !== '' ? strtoupper($extension) : 'FILE',
            'icon_class' => match ($extension) {
                'pdf' => 'is-pdf',
                'ai', 'eps', 'esp' => 'is-vector',
                'xls', 'xlsx', 'csv' => 'is-sheet',
                'jpg', 'jpeg', 'png' => 'is-image',
                default => 'is-document',
            },
        ];
    }

    private static function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format(max(1, (int) ceil($bytes / 1024))).' KB';
    }
}
