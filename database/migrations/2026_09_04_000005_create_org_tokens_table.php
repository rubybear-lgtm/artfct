<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The raw JWT is never stored — only `jti` (the claim the Worker's
     * denylist is keyed on) and a display-only `last_four` fragment of the
     * signed token, so the console can show "...a1b2" without being able to
     * reconstruct the credential (spec 07: "token creation returns value
     * once only").
     */
    public function up(): void
    {
        Schema::create('org_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('jti', 36)->unique();
            $table->string('role');
            $table->string('last_four', 4);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('org_tokens');
    }
};
