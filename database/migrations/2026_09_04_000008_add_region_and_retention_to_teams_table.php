<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `region` is set once at spec-9 provisioning and enforced immutable
     * afterwards by `Team`'s `updating` guard — "moving a tenant between
     * regions is a migration, not a setting" (spec 11). `retention_days`
     * is the per-org retention policy override; null means "use the
     * platform default."
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('region')->nullable()->after('release_version');
            $table->unsignedInteger('retention_days')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['region', 'retention_days']);
        });
    }
};
