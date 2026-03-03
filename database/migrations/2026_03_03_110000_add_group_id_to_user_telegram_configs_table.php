<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_telegram_configs', function (Blueprint $table): void {
            $table->string('group_id', 100)->nullable()->after('default_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_telegram_configs', function (Blueprint $table): void {
            $table->dropColumn('group_id');
        });
    }
};
