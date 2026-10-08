<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 10);
            $table->string('message', 500)->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['site_id', 'type', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_checks');
    }
};
