<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oauth_refresh_tokens', function (Blueprint $table): void {
            // True only when the grant was approved under a Polis session for this
            // team (RUB-482). AuthKit-origin tokens stay false so a switch to
            // SSO-only can refuse and revoke them.
            $table->boolean('polis_proven')->default(false)->after('scopes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->dropColumn('polis_proven');
        });
    }
};
