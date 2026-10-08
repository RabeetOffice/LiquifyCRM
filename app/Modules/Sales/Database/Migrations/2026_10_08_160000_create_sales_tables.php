<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person who has bought at least once; repeat sales attach to the same client.
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamp('first_sale_at')->nullable();
            $table->timestamp('last_sale_at')->nullable();
            $table->decimal('lifetime_value', 14, 2)->default(0);
            $table->unsignedInteger('sales_count')->default(0);
            $table->timestamps();

            $table->index(['brand_id', 'email']);
            $table->index(['brand_id', 'phone']);
            $table->index(['brand_id', 'lifetime_value']);
        });

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('amount_reporting', 14, 2)->nullable();
            $table->string('service', 190)->nullable();
            $table->timestamp('closed_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'closed_at']);
            $table->index(['user_id', 'closed_at']);
            $table->index(['department_id', 'closed_at']);
        });

        // Monthly goals at three levels: brand, department, person (inside a brand).
        Schema::create('targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('targetable_type', 20);
            $table->unsignedBigInteger('targetable_id');
            $table->char('period', 7);
            $table->decimal('revenue_target', 14, 2)->nullable();
            $table->unsignedInteger('sales_count_target')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'targetable_type', 'targetable_id', 'period'], 'targets_unique');
            $table->index(['period', 'targetable_type']);
        });

        // Monthly rates the Admin enters: 1 unit of the currency in the reporting currency.
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('period', 7);
            $table->string('currency', 3);
            $table->decimal('rate_to_reporting', 14, 6);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period', 'currency']);
        });

        // History of every bulk distribution: who moved how many leads to whom.
        Schema::create('lead_distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to_type', 20);
            $table->unsignedBigInteger('to_id');
            $table->unsignedInteger('lead_count');
            $table->string('method', 10);
            $table->json('filters')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_distributions');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('targets');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('clients');
    }
};
