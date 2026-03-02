<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite test environment keeps existing FK to avoid unsupported DDL paths.
        if (! in_array($driver, ['sqlite'], true)) {
            Schema::table('clicks', function (Blueprint $table): void {
                $table->dropForeign(['tracking_link_id']);
            });

            Schema::table('clicks', function (Blueprint $table): void {
                $table->foreignId('tracking_link_id')->nullable()->change();
                $table->foreign('tracking_link_id')
                    ->references('id')
                    ->on('tracking_links')
                    ->nullOnDelete();
            });
        }

        Schema::table('clicks', function (Blueprint $table): void {
            $table->foreignId('connection_id')
                ->nullable()
                ->after('tracking_link_id')
                ->constrained('platform_connections')
                ->nullOnDelete();

            $table->string('attribution_status', 20)
                ->default('matched')
                ->after('sub_id');

            $table->json('source_meta')
                ->nullable()
                ->after('attribution_status');

            $table->index(['connection_id', 'created_at'], 'idx_clicks_connection_created_at');
            $table->index(['attribution_status', 'created_at'], 'idx_clicks_attr_status_created_at');
            $table->index(['referer_domain', 'created_at'], 'idx_clicks_referer_domain_created_at');
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        Schema::table('clicks', function (Blueprint $table) use ($driver): void {
            $table->dropIndex('idx_clicks_connection_created_at');
            $table->dropIndex('idx_clicks_attr_status_created_at');
            $table->dropIndex('idx_clicks_referer_domain_created_at');

            if (! in_array($driver, ['sqlite'], true)) {
                $table->dropForeign(['connection_id']);
            }
            $table->dropColumn(['connection_id', 'attribution_status', 'source_meta']);

            if (! in_array($driver, ['sqlite'], true)) {
                $table->dropForeign(['tracking_link_id']);
            }
        });

        if (! in_array($driver, ['sqlite'], true)) {
            Schema::table('clicks', function (Blueprint $table): void {
                $table->foreignId('tracking_link_id')->nullable(false)->change();
                $table->foreign('tracking_link_id')
                    ->references('id')
                    ->on('tracking_links')
                    ->cascadeOnDelete();
            });
        }
    }
};
