<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_billings', function (Blueprint $table): void {
            $table->index(['user_id', 'period_end'], 'aff_billings_user_period_end_idx');
            $table->index(['user_id', 'status', 'period_end'], 'aff_billings_user_status_period_end_idx');
        });

        Schema::table('affiliate_payouts', function (Blueprint $table): void {
            $table->index(['user_id', 'payout_at'], 'aff_payouts_user_payout_at_idx');
            $table->index(['user_id', 'status', 'payout_at'], 'aff_payouts_user_status_payout_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_billings', function (Blueprint $table): void {
            $table->dropIndex('aff_billings_user_period_end_idx');
            $table->dropIndex('aff_billings_user_status_period_end_idx');
        });

        Schema::table('affiliate_payouts', function (Blueprint $table): void {
            $table->dropIndex('aff_payouts_user_payout_at_idx');
            $table->dropIndex('aff_payouts_user_status_payout_at_idx');
        });
    }
};

