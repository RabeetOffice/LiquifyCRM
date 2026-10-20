<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale made in another currency (user request, 20 Oct 2026): a client of
 * the euro brand who paid in pounds. `amount` stays in the brand's currency,
 * so targets and reports add up as before; these keep what the client was
 * really charged and the rate of that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->decimal('charged_amount', 14, 2)->nullable()->after('amount');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->string('charged_currency', 3)->nullable()->after('currency');
            $table->decimal('charged_amount', 14, 2)->nullable()->after('charged_currency');
            $table->decimal('charged_paid', 14, 2)->nullable()->after('charged_amount');
            // 1 unit of the charged currency in the brand's currency, on the day of the sale.
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('charged_paid');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['charged_currency', 'charged_amount', 'charged_paid', 'exchange_rate']);
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropColumn('charged_amount');
        });
    }
};
