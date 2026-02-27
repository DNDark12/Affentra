<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE platform_connections MODIFY method VARCHAR(30) NOT NULL DEFAULT 'open_api'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE platform_connections ALTER COLUMN method TYPE VARCHAR(30)");
            DB::statement("ALTER TABLE platform_connections ALTER COLUMN method SET DEFAULT 'open_api'");
        }

        DB::table('platform_connections')
            ->whereIn('method', ['api', 'oauth'])
            ->update(['method' => 'open_api']);

        DB::table('platform_connections')
            ->where('method', 'manual')
            ->update(['method' => 'portal_export']);
    }

    public function down(): void
    {
        DB::table('platform_connections')
            ->where('method', 'open_api')
            ->update(['method' => 'oauth']);

        DB::table('platform_connections')
            ->where('method', 'portal_export')
            ->update(['method' => 'manual']);

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE platform_connections MODIFY method ENUM('api','oauth','manual','cookie') NOT NULL DEFAULT 'manual'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE platform_connections ALTER COLUMN method TYPE VARCHAR(30)");
            DB::statement("ALTER TABLE platform_connections ALTER COLUMN method SET DEFAULT 'manual'");
        }
    }
};
