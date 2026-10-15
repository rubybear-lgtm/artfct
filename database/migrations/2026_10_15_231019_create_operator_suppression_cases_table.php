<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time-bound suppression cases (spec 17). While a case is active, operator
     * read events on its tenant skip the tenant copy of the audit log. No
     * foreign key on `team_id`: the case record outlives a tenant erase.
     */
    public function up(): void
    {
        Schema::create('operator_suppression_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('category');
            $table->string('reference');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'released_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_suppression_cases');
    }
};
