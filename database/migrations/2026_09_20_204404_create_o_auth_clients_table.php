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
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 128)->unique();
            $table->string('client_name', 255);
            $table->json('redirect_uris');
            $table->json('grant_types');
            $table->json('response_types');
            $table->string('token_endpoint_auth_method', 32)->default('none');
            $table->unsignedBigInteger('client_id_issued_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
