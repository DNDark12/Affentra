<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_stats', function (Blueprint $table): void {
            $table->index(['user_id', 'date', 'tracking_link_id'], 'idx_daily_stats_user_date_link');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->index(['user_id', 'date_start', 'date_end'], 'idx_campaigns_user_date_range');
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats', function (Blueprint $table): void {
            $table->dropIndex('idx_daily_stats_user_date_link');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('idx_campaigns_user_date_range');
        });
    }
};
