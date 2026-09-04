<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 15: "An agent posts an artifact to an allowlisted channel;
     * posting to a non-allowlisted channel is refused." The allowlist
     * lives on the org token doing the posting, not on the team, so a
     * narrowly-scoped bot token can be issued per integration.
     */
    public function up(): void
    {
        Schema::table('org_tokens', function (Blueprint $table) {
            $table->json('slack_channels')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('org_tokens', function (Blueprint $table) {
            $table->dropColumn('slack_channels');
        });
    }
};
