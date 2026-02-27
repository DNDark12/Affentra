<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('external_id', 100)->nullable()->after('user_id');
            $table->string('source', 50)->default('manual')->after('platform');
            $table->string('external_status', 100)->nullable()->after('status');
            $table->text('description')->nullable()->after('goal_amount');
            $table->text('campaign_url')->nullable()->after('description');
            $table->unsignedBigInteger('impressions')->default(0)->after('campaign_url');
            $table->unsignedBigInteger('clicks')->default(0)->after('impressions');
            $table->string('banner_image_id', 120)->nullable()->after('clicks');
            $table->timestamp('synced_at')->nullable()->after('banner_image_id');
            $table->json('source_meta')->nullable()->after('synced_at');

            $table->unique(['user_id', 'platform', 'external_id'], 'campaigns_user_platform_external_unique');
            $table->index(['platform', 'synced_at'], 'campaigns_platform_synced_idx');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaigns_platform_synced_idx');
            $table->dropUnique('campaigns_user_platform_external_unique');

            $table->dropColumn([
                'external_id',
                'source',
                'external_status',
                'description',
                'campaign_url',
                'impressions',
                'clicks',
                'banner_image_id',
                'synced_at',
                'source_meta',
            ]);
        });
    }
};
