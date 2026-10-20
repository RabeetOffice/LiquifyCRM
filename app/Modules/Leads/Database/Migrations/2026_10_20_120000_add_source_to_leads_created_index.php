<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's "Leads by source" counts the last 30 days by source: with
 * the source in the arrival index it is read from the index alone (about 100
 * ms down to a few with 100,000 leads, 20 Oct 2026). The new index starts like
 * the old one, so it takes its place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index(['brand_id', 'deleted_at', 'created_at', 'source_type'], 'leads_brand_live_created_source_index');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_brand_live_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->index(['brand_id', 'deleted_at', 'created_at'], 'leads_brand_live_created_index');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_brand_live_created_source_index');
        });
    }
};
