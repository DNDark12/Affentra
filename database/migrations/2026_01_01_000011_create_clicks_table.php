<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_link_id')->constrained()->cascadeOnDelete();
            $table->string('sub_id', 100)->nullable()->index('idx_clicks_sub_id');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referer', 2048)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tracking_link_id', 'created_at'], 'idx_clicks_link_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicks');
    }
};
