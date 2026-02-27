<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->index(['tracking_link_id', 'date', 'user_id'], 'idx_daily_stats_link_date_user');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropIndex('idx_daily_stats_link_date_user');
        });
    }
};
