<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21: per-team feature releases. A row means the team was explicitly
 * released for a feature key (`artifact-db`, `artifact-templates`); absent
 * means off. Same table name and semantics as the spec-20 unit's gate so
 * wave 2 keeps one migration and drops the other — these columns are the
 * contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_feature_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_feature_gates');
    }
};
