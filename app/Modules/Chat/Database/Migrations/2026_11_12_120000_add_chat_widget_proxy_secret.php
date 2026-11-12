<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The secret liquify-chat.php signs with, when the chat is served from the brand's own website (12 Nov 2026). Stored
 * encrypted; made the first time somebody downloads the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table): void {
            $table->text('proxy_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table): void {
            $table->dropColumn('proxy_secret');
        });
    }
};
