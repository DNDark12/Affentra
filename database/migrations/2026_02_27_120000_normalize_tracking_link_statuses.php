<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tracking_links')
            ->where('status', 'inactive')
            ->update(['status' => 'paused']);

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tracking_links MODIFY COLUMN status ENUM('active','paused','archived') NOT NULL DEFAULT 'active'");
        }
    }

    public function down(): void
    {
        DB::table('tracking_links')
            ->where('status', 'paused')
            ->update(['status' => 'inactive']);

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tracking_links MODIFY COLUMN status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active'");
        }
    }
};
