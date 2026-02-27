<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // daily_stats: optimize dashboard queries
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->index(['user_id', 'date'], 'idx_daily_stats_dash');
        });

        // tracking_links: optimize listForUser queries
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'created_at'], 'idx_tl_user_status');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropIndex('idx_daily_stats_dash');
        });

        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropIndex('idx_tl_user_status');
        });
    }
};
