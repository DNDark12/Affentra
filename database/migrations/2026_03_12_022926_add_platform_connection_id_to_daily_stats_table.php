<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->foreignId('platform_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['platform_connection_id', 'date'], 'idx_daily_stats_conn_date');
        });

        // Backfill strategy: Link stats to the single active connection per user/platform if exactly 1 exists.
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement("
                UPDATE daily_stats ds
                JOIN (
                    SELECT user_id, platform, MAX(id) as single_id
                    FROM platform_connections
                    GROUP BY user_id, platform
                    HAVING COUNT(id) = 1
                ) pc ON ds.user_id = pc.user_id AND ds.platform = pc.platform
                SET ds.platform_connection_id = pc.single_id
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropIndex('idx_daily_stats_conn_date');
            $table->dropForeign(['platform_connection_id']);
            $table->dropColumn('platform_connection_id');
        });
    }
};
