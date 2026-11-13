<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Voice calls from the website chat box (user request, 12 Nov 2026; docs/CALLS_PLAN.md). The sound goes from the
 * visitor's browser to the team member's browser and is never stored; a row here is who called, who answered, when
 * and how long. `offer` and `answer` are the two set-up messages of a call and are emptied when it ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('status', 16)->default('ringing');
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('offer')->nullable();
            $table->text('answer')->nullable();
            $table->timestamp('rang_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 12)->nullable();
            $table->timestamp('visitor_seen_at')->nullable();
            $table->timestamp('agent_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'rang_at']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_calls');
    }
};
