<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where a visit came from, readable (user request, 12 Nov 2026): "Google Ads", "Google search", "Facebook", "newsletter
 * (email)", and the campaign's name when the link carried one. Worked out when a visit starts, from the ad and campaign
 * tags of the page the visitor landed on and from the website that sent them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table): void {
            $table->string('source', 80)->nullable();
            $table->string('campaign', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table): void {
            $table->dropColumn(['source', 'campaign']);
        });
    }
};
