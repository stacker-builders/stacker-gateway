<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('telegram_bot_id');
            $table->string('username', 64)->nullable();
            $table->string('first_name', 128)->nullable();
            $table->boolean('can_join_groups')->nullable();
            $table->boolean('can_read_all_group_messages')->nullable();
            $table->boolean('supports_inline_queries')->nullable();
            $table->text('token_encrypted');
            $table->string('webhook_path_secret', 64)->unique();
            $table->string('webhook_secret_token', 64);
            $table->string('status', 32)->default('connected')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('webhook_synced_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique('telegram_bot_id');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('telegram_communities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('telegram_bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->string('title', 255)->nullable();
            $table->string('type', 32);
            $table->string('username', 64)->nullable();
            $table->string('status', 32)->default('linked')->index();
            $table->boolean('is_admin')->default(false);
            $table->json('permissions')->nullable();
            $table->boolean('ready_for_delivery')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_bot_id', 'telegram_chat_id'], 'telegram_communities_bot_chat_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('telegram_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('telegram_bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->unsignedBigInteger('update_id');
            $table->string('event_type', 64)->nullable();
            $table->string('status', 24)->default('received');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_bot_id', 'update_id'], 'telegram_webhook_bot_update_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_events');
        Schema::dropIfExists('telegram_communities');
        Schema::dropIfExists('telegram_bots');
    }
};
