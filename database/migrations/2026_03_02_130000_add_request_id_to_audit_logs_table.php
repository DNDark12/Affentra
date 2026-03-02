<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('request_id', 100)
                ->nullable()
                ->after('user_agent')
                ->index('audit_logs_request_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_request_id_idx');
            $table->dropColumn('request_id');
        });
    }
};
