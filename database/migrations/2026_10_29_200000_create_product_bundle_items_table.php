<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_bundle_items')) {
            Schema::create('product_bundle_items', function (Blueprint $table) {
                $table->id();
                $table->string('parent_product_id', 36);
                $table->string('included_product_id', 36);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['parent_product_id', 'included_product_id'], 'product_bundle_items_unique');
                $table->index('parent_product_id');
                $table->index('included_product_id');
            });
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'source')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('source', 32)->nullable()->after('position');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'source')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }

        Schema::dropIfExists('product_bundle_items');
    }
};
