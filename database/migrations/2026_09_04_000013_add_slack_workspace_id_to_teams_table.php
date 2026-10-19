<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Slack workspace, if any, is installed for this org — set once
     * at app-install time. Unique so one Slack workspace can't be mapped
     * to two orgs (which would let a `/artfct` command in one workspace
     * search a different org's corpus).
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('slack_workspace_id')->nullable()->unique()->after('custom_hostname');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('slack_workspace_id');
        });
    }
};
