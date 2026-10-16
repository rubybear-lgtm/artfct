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
        Schema::table('mcp_connections', function (Blueprint $table): void {
            $table->string('protocol_version', 32)->nullable()->after('client_version');
        });

        Schema::table('mcp_activities', function (Blueprint $table): void {
            $table->string('protocol_version', 32)->nullable()->after('client_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_connections', function (Blueprint $table): void {
            $table->dropColumn('protocol_version');
        });

        Schema::table('mcp_activities', function (Blueprint $table): void {
            $table->dropColumn('protocol_version');
        });
    }
};
