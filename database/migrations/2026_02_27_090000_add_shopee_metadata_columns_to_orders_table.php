<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('external_order_id', 100)->nullable()->after('order_code');
            $table->string('shop_id', 64)->nullable()->after('sub_id');
            $table->string('shop_name', 191)->nullable()->after('shop_id');
            $table->string('product_id', 64)->nullable()->after('shop_name');
            $table->string('product_model_id', 64)->nullable()->after('product_id');
            $table->string('product_name', 255)->nullable()->after('product_model_id');
            $table->text('product_link')->nullable()->after('product_name');
            $table->unsignedInteger('product_quantity')->nullable()->after('product_link');
            $table->decimal('commission_platform', 12, 2)->default(0)->after('commission');
            $table->decimal('commission_brand', 12, 2)->default(0)->after('commission_platform');
            $table->decimal('commission_other', 12, 2)->default(0)->after('commission_brand');
            $table->dateTime('click_at')->nullable()->after('ordered_at');
            $table->dateTime('completed_at')->nullable()->after('approved_at');
            $table->json('source_meta')->nullable()->after('missing_sub_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'external_order_id',
                'shop_id',
                'shop_name',
                'product_id',
                'product_model_id',
                'product_name',
                'product_link',
                'product_quantity',
                'commission_platform',
                'commission_brand',
                'commission_other',
                'click_at',
                'completed_at',
                'source_meta',
            ]);
        });
    }
};
