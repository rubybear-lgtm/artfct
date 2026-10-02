<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_activities', function (Blueprint $table): void {
            $table->string('artifact_id', 128)->nullable()->after('tool');
            $table->index(['team_id', 'artifact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_activities', function (Blueprint $table): void {
            $table->dropIndex('mcp_activities_team_id_artifact_id_created_at_index');
            $table->dropColumn('artifact_id');
        });
    }
};
