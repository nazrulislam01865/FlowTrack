<?php
namespace App\Http\Controllers\SupplierBridge;

use App\Models\MasterRecord;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Private, authenticated bridge download; never exposes FlowTracker user sessions. */
final class SupplierProductImage extends Controller
{
    public function __invoke(Request $request, int $supplier, int $product)
    {
        $secret = (string) config('supplier_bridge.secret');
        $time = $request->header('X-Supplier-Asset-Time');
        $signature = $request->header('X-Supplier-Asset-Signature');
        abort_unless(config('supplier_bridge.enabled') && strlen($secret) >= 32
            && is_string($time) && ctype_digit($time) && abs(time() - (int) $time) <= 300
            && is_string($signature) && preg_match('/^[a-f0-9]{64}$/', $signature)
            && hash_equals(hash_hmac('sha256', $supplier."\n".$product."\n".$time, $secret), $signature), 401);

        $record = MasterRecord::query()->ofType('product')->whereKey($product)
            ->first(['id', 'workspace_id', 'type', 'metadata']);
        abort_unless($record && DB::table('product_supplier_links')
            ->where('workspace_id', $record->workspace_id)->where('product_id', $product)
            ->where('supplier_id', $supplier)->exists(), 404);
        $path = trim((string) data_get($record->metadata, 'product_image_path'));
        abort_unless($path !== '' && str_starts_with($path, 'product-images/'.$record->workspace_id.'/'.$product.'/')
            && preg_match('/\.(png|jpe?g|webp)$/i', $path) && Storage::disk('public')->exists($path), 404);
        // Never serve an old photo after a newer manifest has reached the portal.
        abort_unless(hash_equals(hash('sha256', $path), (string) $request->header('X-Supplier-Image-Version', '')), 409);
        abort_unless(Storage::disk('public')->size($path) <= 5 * 1024 * 1024, 413);
        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
