<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat, third update (3 Nov 2026 plan): files sent in a website chat, in both directions (kept on the
 * private disk, one row each), and the team's saved replies (`/price` in the box): the company's, a
 * brand's and a person's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('disk', 20)->default('local');
            $table->string('path', 255);
            $table->string('original_name', 190);
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('canned_replies', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 10);
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('shortcut', 30);
            $table->string('title', 120);
            $table->text('body');
            $table->timestamps();

            $table->index(['scope', 'brand_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('chat_attachments');
    }
};
