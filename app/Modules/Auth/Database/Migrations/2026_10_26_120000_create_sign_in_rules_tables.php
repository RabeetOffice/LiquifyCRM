<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where people may sign in from (26 Oct 2026): a person's own rule for the
 * network and for phones (empty = the rule for everybody), the office and
 * blocked internet addresses, and the phones and tablets people used, each
 * waiting for, given or refused an Admin's approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('sign_in_network', 16)->nullable()->after('timezone');
            $table->string('sign_in_phones', 16)->nullable()->after('sign_in_network');
        });

        Schema::create('sign_in_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16)->index();
            $table->string('address', 64);
            $table->string('label', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'address']);
        });

        Schema::create('sign_in_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->string('status', 16)->index();
            $table->string('user_agent', 512)->nullable();
            $table->string('first_ip', 45)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['token_hash', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_devices');
        Schema::dropIfExists('sign_in_addresses');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['sign_in_network', 'sign_in_phones']);
        });
    }
};
