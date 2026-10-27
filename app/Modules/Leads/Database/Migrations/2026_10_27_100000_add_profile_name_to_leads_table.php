<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name on the person's Facebook or Instagram profile (user report,
 * 27 Oct 2026): a lead form carries whatever the person typed as their name,
 * while Messenger and Instagram only search the profile name, so the team
 * could not find the chat of a lead. Filled from Meta, never typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('profile_name', 160)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('profile_name');
        });
    }
};
