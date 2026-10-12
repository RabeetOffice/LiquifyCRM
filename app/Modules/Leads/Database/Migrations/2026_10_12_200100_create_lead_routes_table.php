<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the leads of one source go by themselves: a website or a Facebook
 * Page (its lead forms and its Messenger chats) sends them straight to a
 * department, or to one person in it. A source with no row leaves its leads
 * in the queue, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'channel', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_routes');
    }
};
