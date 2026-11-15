<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21: idempotency for template pulls. A row records that request
 * (team, template, client-generated request id) already produced an
 * artifact, so a retry or double click returns the same artifact instead
 * of a second copy. Rows older than 24 hours are pruned on pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_pulls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('actor', 64);
            $table->string('template_key', 64);
            $table->string('request_id', 64);
            $table->string('artifact_id', 128);
            $table->timestamps();

            $table->unique(['team_id', 'actor', 'template_key', 'request_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_pulls');
    }
};
