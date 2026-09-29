<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RUB-314: anonymous `/p/{id}` views (Slack opens, shared links — the
     * majority case, where the Worker never resolves a verified
     * `viewer_user_id`) carry a daily-salted pseudonymous `viewer_key`
     * instead. It is only ever set when `actor_user_id` is null.
     *
     * The unique index is the dedup mechanism for "one event per visitor
     * per artifact per day" (see `UsageEventLogger::record()`): the Worker
     * derives `viewer_key` from an HMAC that already folds in the UTC date,
     * so the same visitor's key is identical for every view on the same
     * day and different the next day. A single uniqueness constraint on
     * (team, artifact, viewer_key, event_type) therefore does the whole
     * job, with no separate date column needed, and — unlike a Worker-side
     * dedup — it is naturally idempotent against redelivery of the same
     * `artifact.viewed` event. `event_type` is included so an anonymous
     * `viewed` row and a later `retrieved_then_opened` row (spec 16's
     * search-served correlation) for the same visitor/artifact/day can
     * both exist. Rows with a null `viewer_key` (identified users) are
     * unaffected: standard SQL unique-index semantics treat NULLs as
     * distinct from one another.
     */
    public function up(): void
    {
        Schema::table('artifact_usage_events', function (Blueprint $table) {
            $table->string('viewer_key')->nullable()->after('actor_user_id');
            $table->unique(
                ['team_id', 'artifact_id', 'viewer_key', 'event_type'],
                'artifact_usage_events_viewer_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('artifact_usage_events', function (Blueprint $table) {
            $table->dropUnique('artifact_usage_events_viewer_key_unique');
            $table->dropColumn('viewer_key');
        });
    }
};
