<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resources (user request, 19 Oct 2026): a brand's templates and material
 * that everyone in it downloads, such as the NDA template, the letterhead or
 * the price list. The file itself is a row of the files table (fileable is
 * the resource), as the architecture document designs it; a resource may be
 * a link (a Google Drive folder, a Canva design) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('category', 20)->default('other');
            $table->string('description', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
