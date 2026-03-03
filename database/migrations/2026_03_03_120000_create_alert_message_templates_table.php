<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('metric', 60);
            $table->text('in_app_template');
            $table->text('telegram_template');
            $table->timestamps();

            $table->unique(['user_id', 'metric'], 'alert_message_templates_user_metric_uq');
            $table->index(['user_id', 'updated_at'], 'alert_message_templates_user_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_message_templates');
    }
};

