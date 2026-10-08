<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 20)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('external_id', 120)->nullable();
            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('utm')->nullable();
            $table->json('fields')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['brand_id', 'source_type', 'external_id']);
            $table->index(['brand_id', 'assigned_to', 'status_id']);
            $table->index(['brand_id', 'department_id']);
            $table->index(['brand_id', 'created_at']);
            $table->index(['brand_id', 'next_follow_up_at']);
            $table->index(['brand_id', 'email']);
            $table->index(['brand_id', 'phone']);
            $table->fullText(['name', 'email', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
