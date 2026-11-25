<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_folders')) {
            return;
        }

        Schema::create('product_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 80);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('product_folder_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_folder_id');
            // Mesmo tipo que products.id (char(36) no PostgreSQL após migração UUID — não usar uuid() nativo).
            $table->string('product_id', 36);
            $table->timestamps();

            $table->foreign('product_folder_id')->references('id')->on('product_folders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_folder_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_folder_product');
        Schema::dropIfExists('product_folders');
    }
};
