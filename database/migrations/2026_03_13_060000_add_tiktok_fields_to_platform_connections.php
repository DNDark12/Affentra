<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            // TikTok-specific fields (nullable for backward compat)
            $table->string('shop_id', 100)->nullable()->after('token_expires_at');
            $table->text('shop_cipher')->nullable()->after('shop_id');       // encrypted at app layer
            $table->string('market', 10)->nullable()->after('shop_cipher');  // VN, MY, SG, etc.
            $table->timestamp('token_refreshed_at')->nullable()->after('market');

            // Idempotency: prevent duplicate connections per user+platform+shop
            $table->unique(
                ['user_id', 'platform', 'shop_id'],
                'idx_platform_conn_user_shop'
            );
        });
    }

    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->dropUnique('idx_platform_conn_user_shop');
            $table->dropColumn(['shop_id', 'shop_cipher', 'market', 'token_refreshed_at']);
        });
    }
};
