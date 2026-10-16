<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 12's chunk store: one row per embedded chunk, carrying the
     * provenance metadata every chunk needs (artifact_id, org/team,
     * created_at, agent, repo_url, commit_sha) plus its dense embedding and
     * a generated full-text column for hybrid retrieval (RUB-316,
     * 2026-09-29 architecture decision: pgvector, not Vectorize).
     *
     * Vector and full-text columns are PostgreSQL-only (Laravel's native
     * `vector()`/`fullText()` migration methods). On other drivers (the
     * default SQLite test connection) this creates a portable stand-in
     * table with no vector/full-text columns — nothing binds
     * `PgVectorIndex` there, so it exists only so `php artisan migrate`
     * doesn't fail on the default test suite. Real coverage runs against a
     * real Postgres connection (`phpunit.pgsql.xml`).
     *
     * `'simple'` text-search config deliberately, not `'english'`: English
     * stemming mangles identifiers, repo names and commit SHAs, and exact
     * matches on those are the reason hybrid search exists at all.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::ensureVectorExtensionExists();
        }

        Schema::create('artifact_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->text('text');
            $table->text('search_text');

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', dimensions: 1024);
            } else {
                $table->text('embedding')->nullable();
            }

            $table->string('agent')->nullable();
            $table->string('repo_url')->nullable();
            $table->string('commit_sha')->nullable();
            $table->timestamp('chunk_created_at');
            $table->timestamps();

            $table->index(['team_id', 'artifact_id']);

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->fullText('search_text')->language('simple');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_chunks');
    }
};
