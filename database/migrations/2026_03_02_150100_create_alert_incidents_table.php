<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained('alert_rules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('triggered_value', 15, 4);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'resolved_at'], 'alert_incidents_user_resolved_idx');
            $table->index(['alert_rule_id', 'resolved_at'], 'alert_incidents_rule_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_incidents');
    }
};

