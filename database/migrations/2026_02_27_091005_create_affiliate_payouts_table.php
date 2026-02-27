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
        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            $table->string('platform')->default('shopee');
            $table->string('payout_id')->index();
            $table->timestamp('payout_at')->nullable();
            
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('currency', 10)->default('VND');
            
            $table->string('bank_name')->nullable();
            $table->string('account_number_masked')->nullable();
            
            $table->string('status')->default('completed');
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'payout_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_payouts');
    }
};
