<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where a website visitor is (user request, 12 Nov 2026: "flag, country, IP and location"): the region, network, time
 * zone and whether the address is a VPN or proxy, on the visitor of a chat and on a browser on the website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_visitors', function (Blueprint $table): void {
            $table->string('region', 120)->nullable()->after('city');
            $table->string('isp', 120)->nullable()->after('region');
            $table->string('timezone', 64)->nullable()->after('isp');
            $table->boolean('is_proxy')->nullable()->after('timezone');
        });

        Schema::table('chat_sessions', function (Blueprint $table): void {
            $table->string('ip', 45)->nullable()->after('country');
            $table->string('city', 120)->nullable()->after('ip');
            $table->string('region', 120)->nullable()->after('city');
            $table->string('isp', 120)->nullable()->after('region');
            $table->string('timezone', 64)->nullable()->after('isp');
            $table->boolean('is_proxy')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table): void {
            $table->dropColumn(['ip', 'city', 'region', 'isp', 'timezone', 'is_proxy']);
        });

        Schema::table('chat_visitors', function (Blueprint $table): void {
            $table->dropColumn(['region', 'isp', 'timezone', 'is_proxy']);
        });
    }
};
