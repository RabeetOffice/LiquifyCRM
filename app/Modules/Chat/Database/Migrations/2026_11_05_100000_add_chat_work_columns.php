<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat, phase 3 (3 Nov 2026 plan): the team works a website chat. A visitor with no lead has nobody to
 * decide who looks after their chat, so the chat itself says (`chat_assigned_to`); it is open or closed; and
 * the time of the team's first answer is kept for the reports. A chat that has a lead is assigned through the
 * lead, as every chat is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('chat_assigned_to')->nullable()->after('chat_visitor_id')->constrained('users')->nullOnDelete();
            $table->string('chat_status', 10)->default('open')->after('chat_assigned_to');
            $table->timestamp('chat_closed_at')->nullable()->after('chat_status');
            $table->timestamp('first_response_at')->nullable()->after('chat_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('chat_assigned_to');
            $table->dropColumn(['chat_status', 'chat_closed_at', 'first_response_at']);
        });
    }
};
