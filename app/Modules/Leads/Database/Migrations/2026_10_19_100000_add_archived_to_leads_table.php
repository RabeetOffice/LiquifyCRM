<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archived leads (user request, 19 Oct 2026): a Manager puts a lead that
 * leads nowhere out of the working lists without deleting it. It keeps
 * everything and comes back with one click, or by itself when the person
 * writes again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('last_activity_at');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();

            // The working lists now also ask "not archived": the list and its count, and the
            // Unassigned queue, are still answered from an index (liquify:speed-check).
            $table->index(['brand_id', 'deleted_at', 'archived_at', 'created_at'], 'leads_brand_live_unarchived_created_index');
            $table->index(['brand_id', 'deleted_at', 'archived_at', 'assigned_to'], 'leads_brand_live_unarchived_assignee_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_brand_live_unarchived_created_index');
            $table->dropIndex('leads_brand_live_unarchived_assignee_index');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn('archived_at');
        });
    }
};
