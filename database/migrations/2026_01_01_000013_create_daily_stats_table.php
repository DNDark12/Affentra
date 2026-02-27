<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('platform', 50)->default('shopee');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tracking_link_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('orders')->default(0);
            $table->unsignedBigInteger('approved')->default(0);
            $table->decimal('commission', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['date', 'platform', 'user_id', 'campaign_id'], 'idx_daily_stats_composite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
