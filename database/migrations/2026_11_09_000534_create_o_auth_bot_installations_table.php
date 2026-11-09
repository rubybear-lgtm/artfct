<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent binding of an OAuth public client to one bot teammate per team
 * (RUB-524). Reconnects reuse the same bot; a second OAuth client_id can bind
 * a different bot in the same workspace without colliding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_bot_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('oauth_client_id', 200);
            $table->foreignId('bot_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections')->nullOnDelete();
            $table->foreignId('consented_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'oauth_client_id']);
            $table->index(['bot_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_bot_installations');
    }
};
