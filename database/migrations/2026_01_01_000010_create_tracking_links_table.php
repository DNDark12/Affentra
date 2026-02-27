<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('short_code', 20)->unique();
            $table->string('destination_url', 2048);
            $table->string('platform', 50)->default('shopee')->index();
            $table->json('tags')->nullable();
            $table->json('meta')->nullable();
            $table->string('channel', 100)->nullable()->index();
            $table->string('source', 100)->nullable()->index();
            $table->string('sub_id', 100)->nullable()->index();
            $table->enum('status', ['active', 'paused', 'archived'])->default('active')->index();
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->unsignedBigInteger('orders_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_links_user_status');
            $table->index(['campaign_id', 'status'], 'idx_links_campaign_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_links');
    }
};
