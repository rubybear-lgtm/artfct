<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RUB-529: app-side mirror of the Stripe Team trial. Stripe owns the
 * subscription clock; these columns power the in-app countdown, the
 * one-trial-per-team guard, and a local expire safety net when a
 * webhook is delayed. Existing free teams are granted a trial by
 * `php artisan billing:grant-trials --existing` at release (idempotent),
 * not inside this migration — Stripe must be reachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('trial_started_at')->nullable()->after('stripe_event_at');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['trial_started_at', 'trial_ends_at', 'trial_reminder_sent_at']);
        });
    }
};
