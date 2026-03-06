<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameColumnIfNeeded('clicks', 'ctv_user_id', 'partner_user_id');
        $this->renameColumnIfNeeded('daily_stats', 'ctv_user_id', 'partner_user_id');

        $this->dropIndexIfExists('clicks', 'idx_clicks_ctv_date');
        $this->dropIndexIfExists('daily_stats', 'idx_daily_stats_ctv_date');

        $this->addIndexIfPossible('clicks', ['partner_user_id', 'created_at'], 'idx_clicks_partner_date');
        $this->addIndexIfPossible('daily_stats', ['partner_user_id', 'date'], 'idx_daily_stats_partner_date');
    }

    public function down(): void
    {
        $this->renameColumnIfNeeded('clicks', 'partner_user_id', 'ctv_user_id');
        $this->renameColumnIfNeeded('daily_stats', 'partner_user_id', 'ctv_user_id');

        $this->dropIndexIfExists('clicks', 'idx_clicks_partner_date');
        $this->dropIndexIfExists('daily_stats', 'idx_daily_stats_partner_date');

        $this->addIndexIfPossible('clicks', ['ctv_user_id', 'created_at'], 'idx_clicks_ctv_date');
        $this->addIndexIfPossible('daily_stats', ['ctv_user_id', 'date'], 'idx_daily_stats_ctv_date');
    }

    private function renameColumnIfNeeded(string $table, string $from, string $to): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($from, $to): void {
            $tableBlueprint->renameColumn($from, $to);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($index): void {
                $tableBlueprint->dropIndex($index);
            });
        } catch (\Throwable) {
            // No-op: index may not exist on this environment.
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfPossible(string $table, array $columns, string $index): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $index): void {
                $tableBlueprint->index($columns, $index);
            });
        } catch (\Throwable) {
            // No-op: index may already exist.
        }
    }
};
