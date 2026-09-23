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
            $table->string('credential_jti')->nullable()->unique()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_connections', function (Blueprint $table): void {
            $table->dropUnique(['credential_jti']);
            $table->dropColumn('credential_jti');
        });
    }
};
