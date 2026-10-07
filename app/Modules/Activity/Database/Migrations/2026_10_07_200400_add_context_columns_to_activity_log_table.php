<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable()->after('id');
            $table->unsignedBigInteger('department_id')->nullable()->after('brand_id');
            $table->string('ip', 45)->nullable()->after('properties');
            $table->string('user_agent', 512)->nullable()->after('ip');

            $table->index(['brand_id', 'created_at']);
            $table->index(['brand_id', 'event', 'created_at']);
            $table->index(['department_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['brand_id', 'created_at']);
            $table->dropIndex(['brand_id', 'event', 'created_at']);
            $table->dropIndex(['department_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['brand_id', 'department_id', 'ip', 'user_agent']);
        });
    }
};
