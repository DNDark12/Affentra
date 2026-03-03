<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_generations', function (Blueprint $table) {
            $table->id();

            // Ownership & context
            $table->foreignId('tracking_link_id')->constrained('tracking_links')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Generation type/platform — determines sync vs async execution
            $table->string('type', 20);        // text | image | video
            $table->string('platform', 30);    // facebook | tiktok | instagram | generic

            // Status lifecycle (no "queued" for text-sync)
            $table->string('status', 20)->default('running'); // running | succeeded | failed | canceled

            // Prompt spec — store template ref + attributes, NOT raw prompt
            $table->string('prompt_template_id', 100)->nullable();
            $table->json('prompt_attributes')->nullable();
            $table->char('prompt_hash', 64)->nullable()->index(); // SHA256 of (template_id + normalized attributes)

            // Flag for cache bypass
            $table->boolean('force_new_seed')->default(false);
            $table->boolean('from_cache')->default(false);

            // Output
            // Schema: { variants: [{ kind: 'caption'|'post'|'hashtags'|'script', text: '...' }] }
            $table->json('output_payload')->nullable();

            // AI provider cost tracking
            $table->string('ai_provider', 50)->nullable();  // gemini | openai | ...
            $table->string('ai_model', 100)->nullable();    // gemini-1.5-flash | gpt-4o-mini
            $table->unsignedInteger('tokens_prompt')->nullable();
            $table->unsignedInteger('tokens_completion')->nullable();
            $table->decimal('cost_amount', 10, 6)->nullable();
            $table->string('cost_currency', 5)->nullable();

            // Error info
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Indexes for history queries
            $table->index(['tracking_link_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_generations');
    }
};
