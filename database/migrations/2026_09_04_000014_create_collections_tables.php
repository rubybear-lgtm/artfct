<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 16: "kept deliberately thin" — a name, a description, whether
     * it's pinned canonical, and its members. No approval workflow, no
     * review state, no owner assignment.
     */
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('canonical')->default(false);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('collection_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            // Artifacts live in the Worker's D1, not a local `artifacts`
            // table — the same reason ArtifactIndexEntry (spec 12) and
            // ArtifactIndexingFailure key on a bare string id rather than
            // a foreign key.
            $table->string('artifact_id');
            $table->timestamp('added_at');

            $table->unique(['collection_id', 'artifact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_artifacts');
        Schema::dropIfExists('collections');
    }
};
