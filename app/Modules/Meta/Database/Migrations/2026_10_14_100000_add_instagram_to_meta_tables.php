<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Instagram professional account linked to each Page: its direct messages arrive under the account's id.
        Schema::table('meta_sources', function (Blueprint $table): void {
            $table->string('instagram_id', 64)->nullable()->unique()->after('external_id');
            $table->string('instagram_username', 190)->nullable()->after('instagram_id');
        });

        // The @username of the person in an Instagram chat (Messenger has none). Added last: Instagram::ready() looks for it.
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('contact_handle', 190)->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('contact_handle');
        });

        Schema::table('meta_sources', function (Blueprint $table): void {
            $table->dropUnique(['instagram_id']);
            $table->dropColumn(['instagram_id', 'instagram_username']);
        });
    }
};
