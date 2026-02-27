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
        Schema::table('platform_connections', function (Blueprint $table) {
            // Cookie fields
            $table->text('cookie_header')->nullable()->after('app_secret');
            $table->text('cookie_user_agent')->nullable()->after('cookie_header');
            $table->string('cookie_source')->nullable()->after('cookie_user_agent')->comment('manual or curl');
            
            $table->timestamp('cookie_validated_at')->nullable()->after('status');
            $table->timestamp('consent_acknowledged_at')->nullable()->after('cookie_validated_at');
            
            // Make OAuth credentials nullable
            $table->string('app_id')->nullable()->change();
            $table->string('app_secret')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->dropColumn([
                'cookie_header',
                'cookie_user_agent',
                'cookie_source',
                'cookie_validated_at',
                'consent_acknowledged_at'
            ]);
            
            $table->string('app_id')->nullable(false)->change();
            $table->string('app_secret')->nullable(false)->change();
        });
    }
};
