<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One compressed file per day of the audit trail, e.g. logs/2026/10/2026-10-06.jsonl.gz.
        Schema::create('log_archives', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('rows');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->timestamps();
        });

        // "Download last 7 / 15 / 30 days": built in the queue, link valid for 24 hours.
        Schema::create('log_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->json('department_ids')->nullable();
            $table->unsignedSmallInteger('range_days');
            $table->string('status', 10)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('activity_log', function (Blueprint $table): void {
            // The Logs screen filters by date first, then by brand.
            $table->index(['brand_id', 'created_at'], 'activity_log_brand_created_index');
            $table->index('created_at', 'activity_log_created_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Set when a new Manager or Agent has seen (or skipped) the welcome tour.
            $table->timestamp('tour_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('tour_seen_at');
        });

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_brand_created_index');
            $table->dropIndex('activity_log_created_index');
        });

        Schema::dropIfExists('log_exports');
        Schema::dropIfExists('log_archives');
    }
};
