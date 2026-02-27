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
        Schema::create('affiliate_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            $table->string('platform')->default('shopee');
            $table->string('billing_id')->index(); // Shopee Settlement ID
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            
            $table->decimal('total_commission', 16, 2)->default(0);
            $table->decimal('service_fee', 16, 2)->default(0);
            $table->decimal('net_amount', 16, 2)->default(0);
            
            $table->string('status')->default('pending'); // pending, settled, paid
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'billing_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_billings');
    }
};
