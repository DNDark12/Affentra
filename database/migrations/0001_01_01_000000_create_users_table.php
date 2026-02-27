<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['owner', 'leader', 'ctv'])->default('ctv')->index();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active')->index();
            $table->tinyInteger('depth')->unsigned()->default(0);
            $table->string('path', 500)->nullable()->index();
            $table->rememberToken();
            $table->timestamps();

            $table->index('parent_id', 'idx_users_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
