<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor moves from Filament's columns to Laravel Fortify's. Secrets
 * stored by Filament used a different encryption format, so they are
 * cleared and people set up their authenticator app again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('app_authentication_secret', 'two_factor_secret');
            $table->renameColumn('app_authentication_recovery_codes', 'two_factor_recovery_codes');
            $table->dropColumn('has_email_authentication');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        DB::table('users')->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_confirmed_at');
            $table->boolean('has_email_authentication')->default(false)->after('two_factor_recovery_codes');
            $table->renameColumn('two_factor_recovery_codes', 'app_authentication_recovery_codes');
            $table->renameColumn('two_factor_secret', 'app_authentication_secret');
        });
    }
};
