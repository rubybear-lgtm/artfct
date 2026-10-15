<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The internal operator log (spec 17). Append-only like `audit_events`:
     * no `updated_at`, no soft deletes. `team_id` and `suppression_case_id`
     * deliberately have no foreign keys, so the record survives a tenant erase.
     */
    public function up(): void
    {
        Schema::create('operator_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('team_slug')->nullable();
            $table->string('event_type');
            $table->unsignedBigInteger('staff_user_id')->nullable();
            $table->string('target');
            $table->text('reason')->nullable();
            $table->string('ip');
            $table->string('user_agent');
            $table->string('outcome');
            $table->unsignedBigInteger('suppression_case_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'created_at']);
            $table->index(['staff_user_id', 'team_id', 'event_type', 'created_at'], 'operator_audit_events_dedupe_index');
            $table->index('suppression_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_audit_events');
    }
};
