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
            $table->dropForeign(['user_id']);
            $table->dropUnique('idx_platform_conn_user');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unique(['user_id', 'platform'], 'idx_platform_conn_user');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
