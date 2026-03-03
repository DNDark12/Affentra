<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_settings', function (Blueprint $table) {
            $table->string('last_test_status')->nullable()->after('status');   // 'ok' | 'error'
            $table->string('last_test_error', 500)->nullable()->after('last_test_status');
            $table->timestamp('last_tested_at')->nullable()->after('last_test_error');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_settings', function (Blueprint $table) {
            $table->dropColumn(['last_test_status', 'last_test_error', 'last_tested_at']);
        });
    }
};
