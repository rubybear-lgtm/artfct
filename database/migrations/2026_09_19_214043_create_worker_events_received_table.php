<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_events_received', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('type');
            $table->string('org_id');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_events_received');
    }
};
