<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Note: ALTER TABLE ... MODIFY COLUMN with ENUM is MySQL-specific.
     * SQLite (used in tests) stores status as a plain string, which is
     * equivalent — no schema change is needed there.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE platform_connections MODIFY COLUMN status ENUM('active','inactive','error','expired','blocked','deleted','needs_revalidation') NOT NULL DEFAULT 'inactive'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE platform_connections MODIFY COLUMN status ENUM('active','inactive','error','expired','blocked','deleted') NOT NULL DEFAULT 'inactive'");
        }
    }
};
