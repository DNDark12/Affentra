<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_no', 30)->unique();
            $table->string('status', 20)->default('draft')->index(); // draft|finalized|exported
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedInteger('payout_count')->default(0);
            $table->text('note')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['created_by', 'created_at'], 'payout_batches_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
