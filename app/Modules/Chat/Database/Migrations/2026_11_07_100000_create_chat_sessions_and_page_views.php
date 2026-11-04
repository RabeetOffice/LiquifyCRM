<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat, fourth update (3 Nov 2026 plan): who is on a brand's website now, and the pages they read. The chat box's
 * launcher on the website gives the browser a random code and tells the server, every half minute, which page it is on
 * (the address without the part after "?", the page title, where the person came from). A "session" is that browser:
 * seen now when it was seen in the last minute and a half. A session is linked to a chat visitor once they chat, so
 * the team sees the pages that led to the chat; and it holds an invitation the team sent, to show on the next ping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_widget_id')->nullable()->constrained('chat_widgets')->nullOnDelete();
            $table->string('vid', 40);
            $table->foreignId('chat_visitor_id')->nullable()->constrained('chat_visitors')->nullOnDelete();
            $table->string('current_url', 500)->nullable();
            $table->string('current_title', 190)->nullable();
            $table->string('referrer', 190)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device', 20)->nullable();
            $table->string('browser', 60)->nullable();
            $table->unsignedInteger('visits_count')->default(1);
            $table->timestamp('visit_started_at')->nullable();
            $table->timestamp('page_changed_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('invite_text', 300)->nullable();
            $table->string('invite_from', 60)->nullable();
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamp('invite_shown_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'vid']);
            $table->index(['brand_id', 'last_seen_at']);
        });

        Schema::create('chat_page_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('title', 190)->nullable();
            $table->timestamp('viewed_at');

            $table->index(['chat_session_id', 'id']);
            $table->index(['brand_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_page_views');
        Schema::dropIfExists('chat_sessions');
    }
};
