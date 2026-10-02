<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('org_token_revocation_retries', function (Blueprint $table) {
            $table->id();
            $table->char('jti_hash', 64)->unique();
            $table->text('encrypted_jti');
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('retry_after')->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('org_token_revocation_retries');
    }
};
