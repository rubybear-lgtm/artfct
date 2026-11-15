<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The onboarding steps only the person's own agent can see (it read the
     * rules, it explained the tool approvals) and the person's answer to each:
     * `done` or `skipped`. Steps the server can see for itself, such as the
     * first connection, are read from MCP activity instead and never stored
     * here, so this table cannot disagree with what really happened. The
     * project instructions are done once per project, not once per person, so
     * they are never stored here either.
     */
    public function up(): void
    {
        Schema::create('onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('step', 64);
            $table->string('outcome', 16);
            $table->string('host', 32)->nullable();
            $table->string('guidance_version', 16);
            $table->timestamps();

            $table->unique(['user_id', 'step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_progress');
    }
};
