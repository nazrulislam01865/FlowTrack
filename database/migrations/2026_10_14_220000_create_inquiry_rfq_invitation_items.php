<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inquiry_rfq_invitation_items', function (Blueprint $table): void {
            $table->foreignId('invitation_id')->constrained('inquiry_rfq_invitations')->cascadeOnDelete();
            $table->foreignId('inquiry_item_id')->constrained('inquiry_items')->cascadeOnDelete();
            $table->primary(['invitation_id', 'inquiry_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_rfq_invitation_items');
    }
};
