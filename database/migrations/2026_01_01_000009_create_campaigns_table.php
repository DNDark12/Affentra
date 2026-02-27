<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('platform', 50)->default('shopee')->index();
            $table->enum('status', ['active', 'paused', 'ended'])->default('active')->index();
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('goal_amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_campaigns_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
