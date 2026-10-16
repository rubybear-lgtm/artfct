<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator console privileges (spec 17). `staff_at` marks artfct staff;
     * `case_manager_at` is a separate privilege to open, renew or release a
     * suppression case. Neither is mass-assignable or reachable from a request.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('staff_at')->nullable();
            $table->timestamp('case_manager_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['staff_at', 'case_manager_at']);
        });
    }
};
