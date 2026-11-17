<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_product_portal_access', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('supplier_id');
            $table->boolean('enabled')->default(false);
            $table->json('permissions');
            $table->timestamps();
            $table->unique('supplier_id');
            $table->index(['workspace_id', 'enabled']);
        });
        Schema::create('supplier_product_portal_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_user_id');
            $table->string('requested_group', 40)->default('product');
            $table->string('session_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'supplier_user_id', 'revoked_at'], 'supplier_product_portal_revoke_idx');
        });
        Schema::create('supplier_product_portal_actors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_user_id');
            $table->foreignId('flowtrack_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['supplier_id', 'supplier_user_id'], 'supplier_product_actor_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_portal_actors');
        Schema::dropIfExists('supplier_product_portal_sessions');
        Schema::dropIfExists('supplier_product_portal_access');
    }
};
