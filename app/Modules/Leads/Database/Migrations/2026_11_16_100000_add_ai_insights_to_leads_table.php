<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistant works out about a lead (user request, 16 Nov 2026): how ready to buy a new enquiry sounds
 * (`heat`: hot, warm or cold, with a line of why), and why a lost lead was lost (`lost_reason`, with a line of why;
 * `lost_reason_by` is the person who chose it, null when the assistant did). A person can always change either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('heat', 8)->nullable()->index();
            $table->string('heat_reason', 200)->nullable();
            $table->timestamp('heat_at')->nullable();
            $table->string('lost_reason', 24)->nullable()->index();
            $table->string('lost_reason_note', 200)->nullable();
            $table->unsignedBigInteger('lost_reason_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['heat', 'heat_reason', 'heat_at', 'lost_reason', 'lost_reason_note', 'lost_reason_by']);
        });
    }
};
