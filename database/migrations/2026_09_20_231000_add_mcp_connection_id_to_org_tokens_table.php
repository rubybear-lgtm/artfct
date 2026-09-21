<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_tokens', function (Blueprint $table): void {
            $table->foreignId('mcp_connection_id')
                ->nullable()
                ->after('user_id')
                ->constrained('mcp_connections')
                ->nullOnDelete();
            $table->index(['mcp_connection_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('org_tokens', function (Blueprint $table): void {
            $table->dropIndex(['mcp_connection_id', 'revoked_at']);
            $table->dropConstrainedForeignId('mcp_connection_id');
        });
    }
};
