<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a person chose what kind of website this is, custom PHP or WordPress
 * (user request, 3 Nov 2026). The badge was guessed from the homepage only,
 * and one old WordPress picture on a PHP website made it "WordPress"; a
 * chosen type is never guessed over again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('platform_chosen_at')->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('platform_chosen_at');
        });
    }
};
