<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part payments (user request, 19 Oct 2026): a client pays part of the deal
 * up front and the rest later. The sale keeps the full deal amount (it is
 * what counts towards the targets); each payment received is a row here, and
 * the sale remembers how much of it is paid. Every sale recorded before this
 * update was paid in full: it gets one payment, on its own day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at');
            $table->string('note', 190)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('sale_payments')->insertUsing(
            ['brand_id', 'sale_id', 'amount', 'paid_at', 'recorded_by', 'created_at', 'updated_at'],
            DB::table('sales')->select(['brand_id', 'id', 'amount', 'closed_at', 'recorded_by', DB::raw('CURRENT_TIMESTAMP'), DB::raw('CURRENT_TIMESTAMP')]),
        );

        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('amount_paid', 14, 2)->nullable()->after('amount');
        });

        DB::table('sales')->update(['amount_paid' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('amount_paid');
        });

        Schema::dropIfExists('sale_payments');
    }
};
