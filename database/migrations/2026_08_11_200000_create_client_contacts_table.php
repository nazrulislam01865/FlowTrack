<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_contacts')) {
            Schema::create('client_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('name');
                $table->string('job_title')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 60)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['client_id', 'is_primary']);
                $table->index(['client_id', 'sort_order']);
            });
        }

        // Preserve every existing client's legacy primary contact as the first
        // structured contact. The legacy columns remain as a compatibility mirror.
        DB::table('clients')
            ->select(['id', 'contact_name', 'contact_job_title', 'email', 'phone', 'created_at', 'updated_at'])
            ->where(function ($query) {
                $query->whereNotNull('contact_name')
                    ->orWhereNotNull('email')
                    ->orWhereNotNull('phone');
            })
            ->orderBy('id')
            ->chunkById(200, function ($clients): void {
                foreach ($clients as $client) {
                    if (DB::table('client_contacts')->where('client_id', $client->id)->exists()) {
                        continue;
                    }

                    $name = trim((string) ($client->contact_name ?? ''));
                    if ($name === '') {
                        $name = trim((string) ($client->email ?? '')) ?: 'Primary contact';
                    }

                    DB::table('client_contacts')->insert([
                        'client_id' => $client->id,
                        'name' => $name,
                        'job_title' => $client->contact_job_title,
                        'email' => $client->email,
                        'phone' => $client->phone,
                        'is_primary' => true,
                        'sort_order' => 0,
                        'created_at' => $client->created_at ?? now(),
                        'updated_at' => $client->updated_at ?? now(),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
