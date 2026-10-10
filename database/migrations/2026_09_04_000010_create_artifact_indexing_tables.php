<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extracted text is stored separately from the chunks/vectors
        // derived from it — spec 12: "Extraction output is stored so
        // re-embedding with a different model does not require
        // re-rendering — rendering is the expensive step."
        Schema::create('artifact_index_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->boolean('rendered')->default(false);
            $table->longText('extracted_text');
            $table->string('title')->nullable();
            $table->json('headings')->nullable();
            $table->timestamp('extracted_at');
            $table->timestamps();

            $table->unique(['team_id', 'artifact_id']);
        });

        // Dead-letter record for a render/index attempt that exhausted its
        // retries. Kept in its own table, never touching `artifacts` (spec
        // 8/11) or the Worker's serving path — DoD: "A dead-lettered
        // artifact still serves normally."
        Schema::create('artifact_indexing_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->unsignedInteger('attempts');
            $table->text('reason');
            $table->timestamp('failed_at');
            $table->timestamps();

            $table->index(['team_id', 'artifact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_indexing_failures');
        Schema::dropIfExists('artifact_index_entries');
    }
};
