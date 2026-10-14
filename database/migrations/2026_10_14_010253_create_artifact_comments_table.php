<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Threaded comments on an artifact. `author_user_id` is the acting user (a
     * member, or the member an AI tool acts for) and `author_agent` names the
     * tool when the comment came through MCP.
     */
    public function up(): void
    {
        Schema::create('artifact_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('artifact_id');
            $table->foreignId('parent_id')->nullable()->constrained('artifact_comments')->cascadeOnDelete();
            $table->string('author_user_id');
            $table->string('author_name')->nullable();
            $table->string('author_agent')->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'artifact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_comments');
    }
};
