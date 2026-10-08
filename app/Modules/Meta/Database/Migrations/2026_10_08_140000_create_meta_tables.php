<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Facebook login (or System User token). Global, not per brand.
        Schema::create('meta_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('fb_user_id', 64)->unique();
            $table->string('fb_user_name', 190)->nullable();
            $table->text('user_token');
            $table->boolean('is_system_user')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Each Facebook Page, mapped to the brand its leads and chats belong to.
        Schema::create('meta_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('facebook_page');
            $table->string('external_id', 64)->unique();
            $table->string('name', 190);
            $table->text('access_token')->nullable();
            $table->json('subscribed_fields')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'type']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('messenger');
            $table->string('contact_external_id', 64);
            $table->string('contact_name', 190)->nullable();
            $table->foreignId('meta_source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 190)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['meta_source_id', 'contact_external_id']);
            $table->index(['brand_id', 'last_message_at']);
            $table->index(['lead_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->text('body')->nullable();
            $table->string('external_id', 190)->nullable()->unique();
            $table->string('status', 12)->default('sent');
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('meta_sources');
        Schema::dropIfExists('meta_connections');
    }
};
