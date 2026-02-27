<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('platform_connection_id')->nullable()->constrained('platform_connections')->cascadeOnDelete();
            $table->string('integration', 50)->index();

            // Type: import (CSV), auto (scheduled), manual (user-triggered)
            $table->enum('type', ['import', 'auto', 'manual'])->default('import');

            // Extended status taxonomy for ops debugging
            $table->string('status', 30)->default('pending')->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_upserted')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->string('error_log_path', 500)->nullable();
            $table->timestamps();

            $table->index(['platform_connection_id', 'status'], 'idx_sync_runs_conn_status');
            $table->index('started_at', 'idx_sync_runs_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
