<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // First-touch attribution copied from the session at signup ($set_once).
            $table->json('acquisition')->nullable()->after('is_bot');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('heard_about', 64)->nullable()->after('is_personal');
            $table->string('heard_about_other', 120)->nullable()->after('heard_about');
            // Creator first-touch denormalized onto the team for HogQL without paid groups.
            $table->json('acquisition')->nullable()->after('heard_about_other');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('acquisition');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['heard_about', 'heard_about_other', 'acquisition']);
        });
    }
};
