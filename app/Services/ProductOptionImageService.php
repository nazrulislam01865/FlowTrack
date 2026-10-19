<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductOptionImageService
{
    /**
     * @param array<int, array<string, mixed>> $submittedOptions
     * @param array<int, mixed> $uploads
     */
    public function sync(MasterRecord $product, array $submittedOptions, array $uploads = []): MasterRecord
    {
        abort_unless($product->type === 'product', 404);

        $disk = Storage::disk('public');
        $metadata = (array) ($product->metadata ?? []);
        $current = collect((array) ($metadata['product_options'] ?? []))
            ->filter(fn ($option) => is_array($option))
            ->keyBy(fn ($option) => trim((string) ($option['key'] ?? '')));

        $next = [];
        $newPaths = [];
        $deleteAfterSave = [];

        try {
            foreach (array_values($submittedOptions) as $index => $submitted) {
                $label = trim((string) data_get($submitted, 'label'));
                if ($label === '') continue;

                $extraChargeValue = data_get($submitted, 'extra_charge');
                $extraCharge = is_numeric($extraChargeValue) ? max(0, (float) $extraChargeValue) : 0.0;

                $key = trim((string) data_get($submitted, 'key'));
                if ($key === '' || ! preg_match('/^[A-Za-z0-9-]{8,80}$/', $key)) {
                    $key = (string) Str::uuid();
                }

                $old = $current->get($key);
                $oldPath = is_array($old) ? trim((string) ($old['image_path'] ?? '')) : '';
                $imagePath = $oldPath;
                $upload = $uploads[$index] ?? null;

                if ($upload instanceof UploadedFile) {
                    $imagePath = $upload->storePublicly(
                        'product-option-images/'.$product->workspace_id.'/'.$product->id.'/'.$key,
                        'public'
                    );
                    $newPaths[] = $imagePath;
                    if ($oldPath !== '' && $oldPath !== $imagePath) {
                        $deleteAfterSave[] = $oldPath;
                    }
                }

                $row = [
                    'key' => $key,
                    'label' => $label,
                    'extra_charge' => $extraCharge,
                ];
                if ($imagePath !== '') $row['image_path'] = $imagePath;
                $next[] = $row;
            }

            $keptKeys = collect($next)->pluck('key')->all();
            foreach ($current as $key => $old) {
                if ($key !== '' && ! in_array($key, $keptKeys, true)) {
                    $oldPath = trim((string) data_get($old, 'image_path'));
                    if ($oldPath !== '') $deleteAfterSave[] = $oldPath;
                }
            }

            if ($next === []) {
                unset($metadata['product_options']);
            } else {
                $metadata['product_options'] = $next;
            }

            $product->update(['metadata' => $metadata ?: null]);
        } catch (\Throwable $exception) {
            if ($newPaths !== []) $disk->delete($newPaths);
            throw $exception;
        }

        $deleteAfterSave = array_values(array_unique(array_filter($deleteAfterSave)));
        if ($deleteAfterSave !== []) $disk->delete($deleteAfterSave);

        return $product->refresh();
    }

    public function removeAll(MasterRecord $product): MasterRecord
    {
        abort_unless($product->type === 'product', 404);

        $metadata = (array) ($product->metadata ?? []);
        $paths = collect((array) ($metadata['product_options'] ?? []))
            ->map(fn ($option) => trim((string) data_get($option, 'image_path')))
            ->filter()
            ->values()
            ->all();

        unset($metadata['product_options']);
        $product->update(['metadata' => $metadata ?: null]);

        if ($paths !== []) Storage::disk('public')->delete($paths);

        return $product->refresh();
    }
}
