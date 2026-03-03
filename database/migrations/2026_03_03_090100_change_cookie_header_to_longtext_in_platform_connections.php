<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE platform_connections MODIFY cookie_header LONGTEXT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE platform_connections ALTER COLUMN cookie_header TYPE TEXT');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE platform_connections MODIFY cookie_header TEXT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE platform_connections ALTER COLUMN cookie_header TYPE VARCHAR(65535)');
        }
    }
};
