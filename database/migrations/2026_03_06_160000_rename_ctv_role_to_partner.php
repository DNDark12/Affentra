<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if ($this->isMySqlLike()) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner','leader','ctv','partner') NOT NULL DEFAULT 'ctv'");
        }

        DB::table('users')
            ->where('role', 'ctv')
            ->update(['role' => 'partner']);

        if ($this->isMySqlLike()) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner','leader','partner') NOT NULL DEFAULT 'partner'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if ($this->isMySqlLike()) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner','leader','ctv','partner') NOT NULL DEFAULT 'partner'");
        }

        DB::table('users')
            ->where('role', 'partner')
            ->update(['role' => 'ctv']);

        if ($this->isMySqlLike()) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner','leader','ctv') NOT NULL DEFAULT 'ctv'");
        }
    }

    private function isMySqlLike(): bool
    {
        $driver = DB::getDriverName();

        return $driver === 'mysql' || $driver === 'mariadb';
    }
};
