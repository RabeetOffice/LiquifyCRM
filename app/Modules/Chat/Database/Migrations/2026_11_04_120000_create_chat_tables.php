<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat (user request, 3 Nov 2026; docs/LIVECHAT_PLAN.md): one widget per brand (its key, the websites it
 * may run on, its look and its pre-chat form), the visitors who open it, and what the Inbox's chat tables
 * need to hold a website chat next to Messenger and Instagram. A visitor's chat has no lead until the
 * visitor gives an email or phone number, so `conversations.lead_id` stays null until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('widget_key', 40)->unique();
            $table->json('allowed_domains')->nullable();
            $table->json('appearance')->nullable();
            $table->json('prechat')->nullable();
            $table->boolean('agents_see_visitors')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
        });

        Schema::create('chat_visitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_widget_id')->nullable()->constrained('chat_widgets')->nullOnDelete();
            $table->string('visitor_token', 64)->unique();
            $table->string('name', 190)->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('os', 60)->nullable();
            $table->string('device', 20)->nullable();
            $table->unsignedInteger('visits_count')->default(1);
            $table->boolean('is_banned')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'last_seen_at']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('chat_widget_id')->nullable()->after('meta_source_id')->constrained('chat_widgets')->nullOnDelete();
            $table->foreignId('chat_visitor_id')->nullable()->after('chat_widget_id')->constrained('chat_visitors')->nullOnDelete();
        });

        // Last, so that LiveChat::ready() finding `messages.sender_type` means everything above is there.
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('kind', 10)->default('text')->after('direction');
            $table->string('sender_type', 10)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'sender_type']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('chat_visitor_id');
            $table->dropConstrainedForeignId('chat_widget_id');
        });

        Schema::dropIfExists('chat_visitors');
        Schema::dropIfExists('chat_widgets');
    }
};
