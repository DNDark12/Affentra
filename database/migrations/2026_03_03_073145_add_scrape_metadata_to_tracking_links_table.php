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
        Schema::table('tracking_links', function (Blueprint $header) {
            $header->decimal('product_price_value', 15, 2)->nullable()->after('product_price');
            $header->timestamp('product_last_scraped_at')->nullable()->after('product_image_urls');
            $header->decimal('product_scrape_confidence', 5, 2)->nullable()->after('product_last_scraped_at');
            $header->string('product_scrape_source')->nullable()->after('product_scrape_confidence');
            $header->text('product_scrape_error')->nullable()->after('product_scrape_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn([
                'product_price_value',
                'product_last_scraped_at',
                'product_scrape_confidence',
                'product_scrape_source',
                'product_scrape_error',
            ]);
        });
    }
};
