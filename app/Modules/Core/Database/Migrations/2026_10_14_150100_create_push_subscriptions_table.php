<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per browser that asked for desktop alerts with LiquifyCRM closed
 * (Web Push): the address its maker's push service gave it and the keys that
 * encrypt what is sent there. A browser belongs to whoever signed in last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // The address is too long for a unique index of its own.
            $table->char('endpoint_hash', 64)->unique();
            // The browser's keys for encrypting what is sent; stored encrypted.
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
