<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per artifact: its type, and whether the publisher declared it or
     * it was inferred from the content. Kept beside the index, never in the
     * Worker's tables, so adding it needs no Worker migration.
     */
    public function up(): void
    {
        Schema::create('artifact_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->string('artifact_type', 32);
            $table->boolean('declared')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'artifact_id']);
            $table->index(['team_id', 'artifact_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_types');
    }
};
