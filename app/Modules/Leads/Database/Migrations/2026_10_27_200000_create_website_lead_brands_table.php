<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The brands whose website leads a "Website leads" person reads (user request,
 * 27 Oct 2026). Not a brand membership: they open no brand, they only see the
 * website leads of these brands on their own dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_lead_brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_lead_brands');
    }
};
