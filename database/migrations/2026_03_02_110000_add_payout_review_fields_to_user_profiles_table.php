<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('payout_review_status', 20)
                ->default('pending')
                ->after('is_payout_ready')
                ->index('user_profiles_review_status_idx');
            $table->foreignId('payout_reviewed_by')
                ->nullable()
                ->after('payout_review_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('payout_reviewed_at')
                ->nullable()
                ->after('payout_reviewed_by')
                ->index('user_profiles_reviewed_at_idx');
            $table->text('payout_reject_reason')
                ->nullable()
                ->after('payout_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropForeign(['payout_reviewed_by']);
            $table->dropIndex('user_profiles_review_status_idx');
            $table->dropIndex('user_profiles_reviewed_at_idx');
            $table->dropColumn([
                'payout_review_status',
                'payout_reviewed_by',
                'payout_reviewed_at',
                'payout_reject_reason',
            ]);
        });
    }
};

