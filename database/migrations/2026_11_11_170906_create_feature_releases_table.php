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
        Schema::create('feature_releases', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->string('environment', 50);
            $table->string('state')->default('off');
            $table->json('selected_team_ids');
            $table->timestamps();
            $table->unique(['key', 'environment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_releases');
    }
};
