<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->timestamp('exported_at')->nullable()->after('finalized_at');
            $table->foreignId('exported_by')->nullable()->after('exported_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('exported_by');
            $table->dropColumn('exported_at');
        });
    }
};
