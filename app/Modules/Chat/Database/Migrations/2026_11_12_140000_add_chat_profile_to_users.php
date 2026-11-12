<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How a team member shows in the website chat (user request, 12 Nov 2026): a name, a title and a photo of their own for
 * visitors. Empty means their first name, their designation and their profile photo. The photo is a small picture kept as
 * a data address, so the chat box can show it without loading anything from LiquifyCRM's address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('chat_name', 60)->nullable();
            $table->string('chat_title', 80)->nullable();
            $table->mediumText('chat_photo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['chat_name', 'chat_title', 'chat_photo']);
        });
    }
};
