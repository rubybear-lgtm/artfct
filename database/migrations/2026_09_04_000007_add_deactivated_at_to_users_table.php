<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 10 SCIM de-provisioning: `deactivated_at` blocks login without
     * deleting the user row or any of their `external_identities` — a
     * SCIM re-activation (or a downgrade back to authkit) must restore
     * access without re-creating the account.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('deactivated_at');
        });
    }
};
