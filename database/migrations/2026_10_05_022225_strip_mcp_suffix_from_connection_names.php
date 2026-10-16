<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Connections set up by hand were named "{client_name} MCP"; new ones use
     * the client name alone, so bring the stored names in line.
     */
    public function up(): void
    {
        DB::table('mcp_connections')
            ->whereNotNull('client_name')
            ->orderBy('id')
            ->each(function (object $connection): void {
                if ($connection->name === "{$connection->client_name} MCP") {
                    DB::table('mcp_connections')
                        ->where('id', $connection->id)
                        ->update(['name' => $connection->client_name]);
                }
            });
    }

    /**
     * The suffix carried no information, so there is nothing to restore.
     */
    public function down(): void
    {
        //
    }
};
