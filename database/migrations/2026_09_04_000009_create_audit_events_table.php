<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only (spec 11): no migration in this project ever adds an
     * `updated_at` column or a soft-delete column to this table, and
     * `AuditEvent` overrides `update()`/`delete()` to throw — the "not
     * merely by convention" guarantee the DoD asks for.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('event_type');
            $table->string('actor');
            $table->string('target');
            $table->string('ip');
            $table->string('user_agent');
            $table->string('outcome');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
