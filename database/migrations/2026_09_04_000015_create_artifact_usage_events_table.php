<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 16's automatic ranking signal: append-only usage events, one
     * row per (view / Slack share / agent-retrieval-then-open /
     * supersession) occurrence. `UsageScorer` (pure) turns a set of these
     * into a decayed score — this table is only ever written to and read,
     * never aggregated in place, so the scoring formula can change without
     * a migration.
     */
    public function up(): void
    {
        Schema::create('artifact_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->string('event_type');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // For a `superseded` event: the artifact_id of the version
            // that superseded this one.
            $table->string('related_artifact_id')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['team_id', 'artifact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_usage_events');
    }
};
