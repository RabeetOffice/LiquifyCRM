<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After the first sale a client is handed to an Upsell department: someone
 * there looks after them, records every later sale under their own name,
 * and keeps the client's files (the `files` table of the architecture
 * document) and saved text. When the work is over the client is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Who looks after the client now: the department, and the person in it.
            $table->foreignId('department_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable()->after('owner_id');
            $table->foreignId('handed_over_by')->nullable()->after('handed_over_at')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('handed_over_by');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('closed_note', 500)->nullable()->after('closed_by');

            $table->index(['brand_id', 'owner_id']);
            $table->index(['brand_id', 'department_id']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            // "front": the sale that won a lead. "upsell": a later sale to the same client.
            // No index of its own: the one on client_id already finds a client's few sales.
            $table->string('kind', 10)->default('front')->after('client_id');
        });

        // More people on one client: a project manager beside the upseller, for example.
        Schema::create('client_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });

        // The `files` table as the architecture document designs it (its Phase 2 Files
        // module builds on it): a file on the private disk that attaches to a client,
        // or to the lead front sales added it from. Its first use is the client's storage.
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->morphs('fileable');
            $table->string('category', 20)->default('document');
            $table->string('disk', 20)->default('local');
            $table->string('path', 255);
            $table->string('original_name', 190);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('visibility', 10)->default('private');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
        });

        // Text the client shared: logins, links, instructions. The body is stored encrypted.
        Schema::create('client_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->longText('body');
            $table->boolean('is_secret')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
        Schema::dropIfExists('files');
        Schema::dropIfExists('client_user');

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'owner_id']);
            $table->dropIndex(['brand_id', 'department_id']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('handed_over_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['handed_over_at', 'closed_at', 'closed_note']);
        });
    }
};
