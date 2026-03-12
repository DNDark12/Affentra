<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            if (!Schema::hasColumn('clicks', 'utm_source')) {
                $table->string('utm_source', 100)->nullable()->after('referer_domain');
            }
            if (!Schema::hasColumn('clicks', 'utm_medium')) {
                $table->string('utm_medium', 100)->nullable()->after('utm_source');
            }
            if (!Schema::hasColumn('clicks', 'utm_campaign')) {
                $table->string('utm_campaign', 255)->nullable()->after('utm_medium');
            }

            if (!Schema::hasIndex('clicks', 'clicks_utm_source_index')) {
                $table->index('utm_source');
            }
            if (!Schema::hasIndex('clicks', 'clicks_utm_medium_index')) {
                $table->index('utm_medium');
            }
            if (!Schema::hasIndex('clicks', 'clicks_utm_campaign_index')) {
                $table->index('utm_campaign');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropIndex(['utm_source']);
            $table->dropIndex(['utm_medium']);
            $table->dropIndex(['utm_campaign']);

            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
