<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One human, many identities, across providers (spec 06). Replaces the
     * `workos_id` column the starter kit would otherwise scaffold onto
     * `users` — see App\Services\AuthKit\IdentityResolver for the
     * resolution logic that reads/writes this table.
     */
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->string('email');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['provider', 'external_id']);
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_identities');
    }
};
