<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('platform_connections')->nullOnDelete();

            // Platform identity — composite unique prevents multi-platform collisions
            $table->enum('platform', ['shopee', 'lazada', 'tiktok', 'other'])->default('shopee');
            $table->string('order_code', 100);
            $table->string('sub_id', 100)->nullable();

            // Order status from platform (NOT payout status)
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // Internal payout tracking (separated from platform status)
            $table->enum('payout_status', ['unpaid', 'processing', 'paid'])->default('unpaid');

            // Financial
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);

            // Timestamps from platform
            $table->dateTime('ordered_at');
            $table->dateTime('approved_at')->nullable();

            // Internal payout tracking
            $table->dateTime('paid_at')->nullable();
            $table->unsignedBigInteger('payout_batch_id')->nullable();

            // Data provenance (Phase 2: API sync)
            $table->enum('source', ['csv', 'api'])->default('csv');
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->string('raw_payload_hash', 64)->nullable();
            $table->boolean('missing_sub_id')->default(false);

            $table->timestamps();

            // ── Indexes ─────────────────────────────────────────
            $table->unique(['platform', 'order_code'], 'uq_orders_platform_code');
            $table->index(['user_id', 'ordered_at'], 'idx_orders_user_ordered');
            $table->index(['platform', 'status'], 'idx_orders_platform_status');
            $table->index('approved_at', 'idx_orders_approved');
            $table->index('sub_id', 'idx_orders_sub_id');
            $table->index(['connection_id', 'ordered_at'], 'idx_orders_conn_ordered');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
