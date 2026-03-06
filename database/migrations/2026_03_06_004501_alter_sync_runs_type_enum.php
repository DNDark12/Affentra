<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE sync_runs MODIFY COLUMN type ENUM('import', 'auto', 'manual', 'payment_sync', 'campaign_sync') DEFAULT 'import'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE sync_runs MODIFY COLUMN type ENUM('import', 'auto', 'manual') DEFAULT 'import'");
    }
};
