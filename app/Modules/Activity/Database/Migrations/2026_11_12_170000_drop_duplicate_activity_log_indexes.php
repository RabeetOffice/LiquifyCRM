<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The logs table had two indexes twice (brand and date, and the date alone): one pair from the day the brand column
 * was added, the same pair again a day later under other names. Every log line wrote all four. The second pair goes.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const TWICE = [
        'activity_log_brand_created_index' => ['brand_id', 'created_at'],
        'activity_log_created_index' => ['created_at'],
    ];

    public function up(): void
    {
        foreach (self::TWICE as $name => $columns) {
            // Only when the first one of the pair is really there: the table is never left without the index.
            if (Schema::hasIndex('activity_log', $name) && Schema::hasIndex('activity_log', $columns)) {
                Schema::table('activity_log', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TWICE as $name => $columns) {
            if (! Schema::hasIndex('activity_log', $name)) {
                Schema::table('activity_log', fn (Blueprint $table) => $table->index($columns, $name));
            }
        }
    }
};
