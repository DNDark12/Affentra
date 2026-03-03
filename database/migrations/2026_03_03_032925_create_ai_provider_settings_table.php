<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->id();

            // Each row belongs to one user (per-user config, not global)
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Provider key: 'gemini' | 'openai' | 'self_hosted' | ...
            $table->string('provider_key', 50);

            // Encrypted bag of credentials:
            // { api_key: '...', base_url: '...', project_id: '...' }
            // Encrypted at application level via Crypt::encrypt
            $table->text('credentials_encrypted')->nullable();

            // Default model for this provider (user-configurable)
            // e.g. 'gemini-1.5-flash', 'gpt-4o-mini', 'deepseek-r1:7b'
            $table->string('default_model', 100)->nullable();

            // Provider status toggled by user: enabled/disabled
            $table->string('status', 20)->default('enabled');

            // Supported modalities for this provider config
            // e.g. ['text', 'image', 'video']
            $table->json('capabilities')->nullable();

            // Custom display label (e.g. "My Local Ollama")
            $table->string('label', 150)->nullable();

            // Daily token quota override (null = use system default)
            $table->unsignedInteger('token_quota_per_day')->nullable();

            $table->timestamps();

            // One row per provider per user
            $table->unique(['user_id', 'provider_key']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_settings');
    }
};
