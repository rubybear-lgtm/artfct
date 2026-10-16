<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('plan')->default('team')->after('retention_days');
            $table->string('payment_status')->default('active')->after('plan');
            $table->string('stripe_subscription_id')->nullable()->after('payment_status');
            $table->unsignedInteger('seats_billed')->nullable()->after('stripe_subscription_id');
            // Unique so "a tenant cannot claim a hostname already claimed
            // by another tenant" is a database constraint, not merely an
            // application-level check that a race condition could slip
            // past.
            $table->string('custom_hostname')->nullable()->unique()->after('seats_billed');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['plan', 'payment_status', 'stripe_subscription_id', 'seats_billed', 'custom_hostname']);
        });
    }
};
