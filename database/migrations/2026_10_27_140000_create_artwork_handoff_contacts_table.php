<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_handoff_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('name', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('contact_type', 40)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Lowercase addresses are saved so an address cannot be added twice
            // to the same workspace, even from two concurrent browser sessions.
            $table->unique(['workspace_id', 'email'], 'artwork_handoff_contacts_unique_email');
            $table->index(['workspace_id', 'id'], 'artwork_handoff_contacts_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_handoff_contacts');
    }
};
