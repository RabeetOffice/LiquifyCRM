<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The person's Messenger picture (an address at Facebook that expires, so it is asked for again now and then).
        Schema::table('conversations', function (Blueprint $table): void {
            $table->text('contact_picture')->nullable()->after('contact_name');
            $table->timestamp('contact_synced_at')->nullable()->after('contact_picture');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['contact_picture', 'contact_synced_at']);
        });
    }
};
