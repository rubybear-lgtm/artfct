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
        Schema::create('mcp_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('credential_jti', 128)->nullable();
            $table->string('actor', 128);
            $table->string('tool', 100);
            $table->string('transport', 50)->default('streamable_http');
            $table->string('request_id', 128)->nullable();
            $table->string('session_id', 128)->nullable();
            $table->string('client_name', 255)->nullable();
            $table->string('outcome', 32);
            $table->unsignedInteger('latency_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'tool', 'created_at']);
            $table->index('credential_jti');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_activities');
    }
};
