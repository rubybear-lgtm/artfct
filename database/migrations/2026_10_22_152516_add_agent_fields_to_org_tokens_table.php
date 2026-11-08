<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent tokens (RUB-511) reuse org_tokens: same JWT shape the Worker
     * already verifies, with an explicit scope list and last-used stamp.
     * Existing API/CI rows stay kind=api with null scopes (middleware falls
     * back to the role's default scope set).
     */
    public function up(): void
    {
        Schema::table('org_tokens', function (Blueprint $table): void {
            $table->string('kind', 16)->default('api')->after('name');
            $table->json('scopes')->nullable()->after('role');
            $table->timestamp('last_used_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('org_tokens', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'scopes', 'last_used_at']);
        });
    }
};
