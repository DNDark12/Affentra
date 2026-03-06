<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->foreignId('platform_connection_id')
                ->nullable()
                ->after('user_id')
                ->constrained('platform_connections')
                ->nullOnDelete();

            $table->index(['user_id', 'platform_connection_id', 'status'], 'idx_tracking_links_user_conn_status');
        });

        Schema::table('content_generations', function (Blueprint $table): void {
            $table->foreignId('platform_connection_id')
                ->nullable()
                ->after('user_id')
                ->constrained('platform_connections')
                ->nullOnDelete();

            $table->index(
                ['user_id', 'platform_connection_id', 'status', 'created_at'],
                'idx_content_generations_user_conn_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_generations', function (Blueprint $table): void {
            $table->dropIndex('idx_content_generations_user_conn_status_created');
            $table->dropConstrainedForeignId('platform_connection_id');
        });

        Schema::table('tracking_links', function (Blueprint $table): void {
            $table->dropIndex('idx_tracking_links_user_conn_status');
            $table->dropConstrainedForeignId('platform_connection_id');
        });
    }
};
