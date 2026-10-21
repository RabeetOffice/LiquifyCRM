<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The time zone a follow-up was set in ("5 PM UK time"): the reminder comes at that moment, and the follow-up
    // shows the time as it was said beside the viewer's own.
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
