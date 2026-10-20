<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Profile used to fill in London for everybody, so saving a profile kept
 * London whatever the person's own time zone. Those go back to Automatic (the
 * computer's own time zone, see ViewerTime); somebody in London still gets
 * London from their computer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('timezone', config('liquify.default_timezone', 'Europe/London'))->update(['timezone' => null]);
    }

    public function down(): void
    {
        // Nothing to undo: Automatic is a choice the person can change in their Profile.
    }
};
