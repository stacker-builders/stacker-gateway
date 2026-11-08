<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Mesmo tipo que products.id (char(36) no PostgreSQL após migração UUID).
            $table->string('product_id', 36);
            $table->foreignId('telegram_bot_id')->nullable()->constrained('telegram_bots')->nullOnDelete();
            $table->unsignedInteger('access_duration')->nullable();
            $table->string('access_duration_unit', 16)->default('days');
            $table->boolean('is_lifetime')->default(false);
            $table->unsignedInteger('invite_expiration_hours')->nullable();
            $table->text('welcome_message')->nullable();
            $table->boolean('auto_remove_on_expiry')->default(true);
            $table->timestamps();

            $table->unique('product_id');
            $table->index(['tenant_id', 'telegram_bot_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_telegram_communities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('product_id', 36);
            $table->foreignId('telegram_community_id')->constrained('telegram_communities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'telegram_community_id'], 'product_telegram_community_unique');
            $table->index(['tenant_id', 'product_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_telegram_communities');
        Schema::dropIfExists('product_telegram_settings');
    }
};
