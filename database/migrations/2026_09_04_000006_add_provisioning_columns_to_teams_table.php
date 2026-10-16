<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 09 tenant provisioning: adds provisioning state to the team
     * that already carries the org identity (spec 06) and auth_mode
     * (spec 06). Does not touch that migration's columns.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('provisioned_at')->nullable();
            $table->string('provisioning_failed_step')->nullable();
            $table->string('release_version')->nullable();
            $table->unsignedInteger('schema_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn([
                'provisioned_at',
                'provisioning_failed_step',
                'release_version',
                'schema_version',
            ]);
        });
    }
};
