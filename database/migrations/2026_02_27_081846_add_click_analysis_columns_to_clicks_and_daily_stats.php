<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('tracking_link_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leader_id')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->foreignId('ctv_user_id')->nullable()->after('leader_id')->constrained('users')->nullOnDelete();
            
            $table->string('fingerprint_hash', 64)->nullable()->after('referer');
            $table->unsignedTinyInteger('hash_version')->default(1)->after('fingerprint_hash');
            $table->boolean('is_bot')->default(false)->after('hash_version');
            $table->string('bot_reason')->nullable()->after('is_bot');
            $table->string('device_type', 50)->nullable()->after('bot_reason');
            $table->string('referer_domain')->nullable()->after('device_type');

            $table->index(['owner_id', 'created_at'], 'idx_clicks_owner_date');
            $table->index(['ctv_user_id', 'created_at'], 'idx_clicks_ctv_date');
            $table->index(['tracking_link_id', 'fingerprint_hash', 'created_at'], 'idx_clicks_link_fp_date');
        });

        Schema::table('daily_stats', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('platform')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leader_id')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->foreignId('ctv_user_id')->nullable()->after('leader_id')->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('unique_clicks')->default(0)->after('clicks');
            $table->unsignedBigInteger('valid_clicks')->default(0)->after('unique_clicks');
            $table->unsignedBigInteger('bot_clicks')->default(0)->after('valid_clicks');

            // Optionally index the new hierarchy for stats
            $table->index(['owner_id', 'date'], 'idx_daily_stats_owner_date');
            $table->index(['ctv_user_id', 'date'], 'idx_daily_stats_ctv_date');
        });
    }

    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['leader_id']);
            $table->dropForeign(['ctv_user_id']);

            $table->dropIndex('idx_clicks_owner_date');
            $table->dropIndex('idx_clicks_ctv_date');
            $table->dropIndex('idx_clicks_link_fp_date');

            $table->dropColumn([
                'owner_id',
                'leader_id',
                'ctv_user_id',
                'fingerprint_hash',
                'hash_version',
                'is_bot',
                'bot_reason',
                'device_type',
                'referer_domain',
            ]);
        });

        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['leader_id']);
            $table->dropForeign(['ctv_user_id']);

            $table->dropIndex('idx_daily_stats_owner_date');
            $table->dropIndex('idx_daily_stats_ctv_date');

            $table->dropColumn([
                'owner_id',
                'leader_id',
                'ctv_user_id',
                'unique_clicks',
                'valid_clicks',
                'bot_clicks',
            ]);
        });
    }
};
