<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 50)->index();
            $table->enum('method', ['api', 'oauth', 'manual', 'cookie'])->default('manual');
            $table->string('app_id', 200)->nullable();
            // Encrypted fields — never logged, never exposed in responses
            $table->text('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->enum('status', ['active', 'inactive', 'error', 'disabled', 'expired'])->default('active')->index();
            $table->enum('sync_mode', ['manual', 'scheduled'])->default('manual');

            // Phase 2: Capability snapshot & backfill config
            $table->json('capabilities')->nullable();
            $table->unsignedSmallInteger('backfill_days_override')->nullable();

            // Sync state tracking
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 30)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'platform'], 'idx_platform_conn_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
