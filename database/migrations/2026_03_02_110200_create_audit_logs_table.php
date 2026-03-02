<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('action', 80);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id'], 'audit_logs_target_idx');
            $table->index(['actor_id', 'created_at'], 'audit_logs_actor_created_idx');
            $table->index('action', 'audit_logs_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

