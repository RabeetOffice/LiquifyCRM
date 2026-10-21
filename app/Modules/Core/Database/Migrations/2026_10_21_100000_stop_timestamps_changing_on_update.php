<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A MariaDB or MySQL server with explicit_defaults_for_timestamp off gives the
 * first NOT NULL timestamp of a table "DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP". Then every save of the row moved it to now: a sale's
 * date when a payment was recorded, a follow-up's due time when it was
 * edited, an invitation's expiry. The columns keep their default and stop
 * changing by themselves. Nothing happens where none of them has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = DB::select(
            "SELECT TABLE_NAME AS name, COLUMN_NAME AS col FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' AND IS_NULLABLE = 'NO'
               AND LOWER(EXTRA) LIKE '%on update%' AND COLUMN_NAME <> 'updated_at'"
        );

        foreach ($columns as $column) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $column->name, $column->col));
        }
    }

    public function down(): void
    {
        // Nothing to put back: the columns changing by themselves was the fault.
    }
};
