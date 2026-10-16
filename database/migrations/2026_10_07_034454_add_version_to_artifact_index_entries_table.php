<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifact_index_entries', function (Blueprint $table) {
            // The artifact version the stored text and vectors came from. Null
            // means the artifact was indexed before versioning existed.
            $table->unsignedInteger('version')->nullable()->after('artifact_id');
        });
    }

    public function down(): void
    {
        Schema::table('artifact_index_entries', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
