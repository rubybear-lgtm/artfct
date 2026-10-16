<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RUB-314: one row per artifact returned by `SearchService::search()`.
     * Deliberately matched by team + artifact + time, not identity (the
     * issue's own wording) — an MCP agent's search and a later human open
     * from an entirely different session both count. `ArtifactViewedHandler`
     * reads this to record a `retrieved_then_opened` usage event when a view
     * lands within 24h of a served result for the same team/artifact.
     *
     * `opened_at` makes this a one-time claim rather than a standing fact: a
     * view "spends" the oldest unclaimed served row in its window (a
     * conditional update guarded by `whereNull('opened_at')`) rather than
     * matching every served row in range. Without this, one search result
     * would correlate an unbounded number of unrelated later views (every
     * reload, every other visitor's Slack open) as "retrieved then opened"
     * for as long as it stayed in the 24h window.
     */
    public function up(): void
    {
        Schema::create('search_results_served', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->timestamp('served_at');
            $table->timestamp('opened_at')->nullable();

            $table->index(['team_id', 'artifact_id', 'served_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_results_served');
    }
};
