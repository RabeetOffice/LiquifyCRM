<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (user request, 19 Oct 2026), made from a sale. Each keeps what it
 * said when it was made (who it was for, what, how much was paid), so the
 * same number always means the same paper; its PDF is in the client's files.
 * Numbers run per brand and year: UKPH-2026-0001.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->json('bill_to');
            $table->json('items');
            $table->decimal('total', 14, 2);
            $table->decimal('paid', 14, 2)->default(0);
            $table->string('currency', 3);
            $table->text('note')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'number']);
            $table->unique(['brand_id', 'year', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
