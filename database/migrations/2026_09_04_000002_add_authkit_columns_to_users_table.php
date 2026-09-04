<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * AuthKit-created users have no local password and no `workos_id`
     * column — identity lives in `external_identities` instead (spec 06).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('avatar')->nullable()->after('email_verified_at');
            $table->foreignId('current_team_id')->nullable()->after('avatar')
                ->constrained('teams')->nullOnDelete();
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_team_id');
            $table->dropColumn('avatar');
            $table->string('password')->nullable(false)->change();
        });
    }
};
