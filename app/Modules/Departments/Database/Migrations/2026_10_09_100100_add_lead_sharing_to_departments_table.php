<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a department shares the leads it receives: by hand (the Manager
 * assigns each one) or round robin (each new lead goes to the next Agent in
 * turn). `last_assigned_user_id` remembers whose turn it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('assignment_mode', 20)->default('manual')->after('color');
            $table->unsignedBigInteger('last_assigned_user_id')->nullable()->after('assignment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['assignment_mode', 'last_assigned_user_id']);
        });
    }
};
