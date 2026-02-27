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
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email_verified_at');
            $table->string('phone')->nullable()->after('avatar');
            $table->string('password')->nullable()->change();
            
            // Note: Changing ENUM natively is supported in MySQL in modern Laravel
            $table->enum('status', ['pending', 'active', 'suspended', 'banned', 'rejected'])
                  ->default('pending')
                  ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'phone']);
            $table->string('password')->nullable(false)->change();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active')->change();
        });
    }
};
