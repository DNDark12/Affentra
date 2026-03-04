<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_generations', function (Blueprint $table) {
            $table->string('provider_task_id', 255)->nullable()->index()
                ->after('error_message')
                ->comment('External async task ID (e.g. Seedance task_id)');

            $table->string('provider_status', 30)->nullable()
                ->after('provider_task_id')
                ->comment('Raw provider status: queued|processing|completed|failed');

            $table->unsignedSmallInteger('poll_attempts')->default(0)
                ->after('provider_status')
                ->comment('Number of times the polling job has checked provider status');

            $table->timestamp('provider_completed_at')->nullable()
                ->after('poll_attempts')
                ->comment('When the async provider finished (regardless of success/failure)');
        });
    }

    public function down(): void
    {
        Schema::table('content_generations', function (Blueprint $table) {
            $table->dropIndex(['provider_task_id']);
            $table->dropColumn([
                'provider_task_id',
                'provider_status',
                'poll_attempts',
                'provider_completed_at',
            ]);
        });
    }
};
