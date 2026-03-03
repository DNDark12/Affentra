<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_payouts', function (Blueprint $table): void {
            $table->foreignId('payout_batch_id')
                ->nullable()
                ->after('platform_connection_id')
                ->constrained('payout_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_batch_id');
        });
    }
};
