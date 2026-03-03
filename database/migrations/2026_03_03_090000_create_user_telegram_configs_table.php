<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_telegram_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('bot_token')->nullable();
            $table->string('default_chat_id', 100)->nullable();
            $table->boolean('is_enabled')->default(false)->index();
            $table->boolean('fallback_to_in_app')->default(true);
            $table->string('last_test_status', 20)->nullable()->index();
            $table->text('last_test_error')->nullable();
            $table->timestamp('last_test_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'is_enabled'], 'telegram_cfg_user_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_telegram_configs');
    }
};

