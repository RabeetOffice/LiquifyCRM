<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes can be corrected (user request, 4 Nov 2026: agents write a wrong note
 * and need to fix it). The note says it was edited, by whom and when; what it
 * said before is kept in the log entry of the edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_notes', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('is_pinned');
            $table->foreignId('edited_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn('edited_at');
        });
    }
};
