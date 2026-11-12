<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When somebody first scrolled, tapped, clicked, typed or moved the mouse on the website (user request, 12 Nov 2026: tell
 * a real person from a bot). A browser that never does any of it, from a data centre, is probably not a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table): void {
            $table->timestamp('interacted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table): void {
            $table->dropColumn('interacted_at');
        });
    }
};
