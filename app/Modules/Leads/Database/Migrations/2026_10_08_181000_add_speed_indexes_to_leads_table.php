<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every lead query filters on brand_id and "not deleted". With deleted_at
        // inside the index, counts and lists are answered from the index alone
        // (measured with 100,000 leads in one brand: liquify:speed-check).
        Schema::table('leads', function (Blueprint $table): void {
            $table->index(['brand_id', 'deleted_at', 'status_id'], 'leads_brand_live_status_index');
            $table->index(['brand_id', 'deleted_at', 'assigned_to'], 'leads_brand_live_assignee_index');
            $table->index(['brand_id', 'deleted_at', 'created_at'], 'leads_brand_live_created_index');
            $table->index(['brand_id', 'deleted_at', 'department_id'], 'leads_brand_live_department_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_brand_live_status_index');
            $table->dropIndex('leads_brand_live_assignee_index');
            $table->dropIndex('leads_brand_live_created_index');
            $table->dropIndex('leads_brand_live_department_index');
        });
    }
};
