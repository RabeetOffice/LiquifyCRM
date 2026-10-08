<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw log of everything a website (and, from week 4, Meta) sends, so a
        // lead that could not be processed is kept and can be retried.
        Schema::create('inbound_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 120)->nullable();
            $table->json('payload');
            $table->string('status', 10)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_id', 'external_id']);
            $table->index(['source', 'source_id', 'id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_events');
    }
};
