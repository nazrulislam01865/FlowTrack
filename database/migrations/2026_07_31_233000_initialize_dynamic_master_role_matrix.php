<?php

use App\Modules\OrderFlow\Business\Services\RoleMatrixService;
use App\Modules\OrderFlow\Data\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_module_access')) {
            return;
        }

        /** @var RoleMatrixService $service */
        $service = app(RoleMatrixService::class);

        Role::query()
            ->with(['moduleAccess', 'permissions:id,slug'])
            ->orderBy('id')
            ->chunkById(50, function ($roles) use ($service): void {
                foreach ($roles as $role) {
                    $service->ensureInitialized($role);
                }
            });
    }

    public function down(): void
    {
        // Data repair migration only. Existing role matrices are intentionally preserved.
    }
};
