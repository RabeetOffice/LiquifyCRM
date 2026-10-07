<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->nullable()->after('password')->index();
            $table->boolean('is_protected')->default(false)->after('role');
            $table->string('status', 20)->default('active')->after('is_protected')->index();
            $table->string('phone', 32)->nullable()->after('status');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->string('timezone', 64)->nullable()->after('avatar_path');
            $table->timestamp('last_login_at')->nullable()->after('timezone');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->text('app_authentication_secret')->nullable()->after('remember_token');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->boolean('has_email_authentication')->default(false)->after('app_authentication_recovery_codes');
            $table->foreignId('created_by')->nullable()->after('has_email_authentication')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'role', 'is_protected', 'status', 'phone', 'avatar_path', 'timezone',
                'last_login_at', 'last_login_ip', 'app_authentication_secret',
                'app_authentication_recovery_codes', 'has_email_authentication',
            ]);
        });
    }
};
