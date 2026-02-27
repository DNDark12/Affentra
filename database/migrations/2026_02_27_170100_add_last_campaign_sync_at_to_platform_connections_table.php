<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table): void {
            $table->timestamp('last_campaign_sync_at')->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table): void {
            $table->dropColumn('last_campaign_sync_at');
        });
    }
};
