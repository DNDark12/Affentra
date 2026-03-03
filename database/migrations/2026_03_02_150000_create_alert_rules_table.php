<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('metric', 60)->index();
            $table->string('operator', 8);
            $table->decimal('threshold', 15, 4);
            $table->string('channel', 20)->default('in_app')->index(); // in_app|telegram|both
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'alert_rules_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};

