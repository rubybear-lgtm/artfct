<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RUB-610: several bots may share one OAuth public client on a team, each with
 * its own installation row. Replace the (team, client) unique key with
 * (team, client, bot) additively — no rows are dropped or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_bot_installations', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'oauth_client_id']);
            $table->unique(['team_id', 'oauth_client_id', 'bot_user_id']);
        });
    }

    public function down(): void
    {
        // Restoring the old unique key fails once a team has more than one bot
        // on the same oauth_client_id. Forward-fix with a data cleanup first.
        Schema::table('oauth_bot_installations', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'oauth_client_id', 'bot_user_id']);
            $table->unique(['team_id', 'oauth_client_id']);
        });
    }
};
