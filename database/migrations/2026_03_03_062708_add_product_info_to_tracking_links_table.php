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
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('destination_url');
            $table->json('product_image_urls')->nullable()->after('product_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'product_image_urls']);
        });
    }
};
