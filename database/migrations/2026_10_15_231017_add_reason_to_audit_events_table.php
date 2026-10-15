<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only (spec 17): the reason a staff member gave for a mutating
     * action. The table stays append-only: still no `updated_at`, still no
     * soft deletes.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->text('reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
