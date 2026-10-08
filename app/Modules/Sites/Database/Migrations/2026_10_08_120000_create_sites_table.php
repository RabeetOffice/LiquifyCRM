<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('url', 255);
            $table->string('domain', 190);
            $table->string('site_key', 40)->unique();
            $table->text('secret');
            $table->string('mode', 10)->default('push');
            $table->string('platform', 20)->default('php');
            $table->string('form_path', 190)->nullable();
            $table->string('favicon_path')->nullable();
            $table->text('pull_config')->nullable();
            $table->unsignedBigInteger('pull_cursor')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->string('last_pull_note', 255)->nullable();
            $table->string('health_status', 10)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->timestamp('quiet_notified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'is_active']);
            $table->index(['mode', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
