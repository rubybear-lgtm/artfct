<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // New teams start on the free plan; existing rows keep their plan.
            $table->string('plan')->default('free')->change();
            $table->string('stripe_customer_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn('stripe_customer_id');
            $table->string('plan')->default('team')->change();
        });
    }
};
