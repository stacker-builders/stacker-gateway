<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_buyer_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('telegram_username', 64)->nullable();
            $table->string('telegram_first_name', 128)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'telegram_user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('telegram_access_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('product_id', 36);
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('telegram_bot_id')->nullable()->constrained('telegram_bots')->nullOnDelete();
            $table->string('status', 32)->default('pending_invite')->index();
            $table->string('delivery_status', 32)->default('pending')->index();
            $table->timestamp('paid_at');
            $table->timestamp('access_starts_at');
            $table->timestamp('access_ends_at')->nullable()->index();
            $table->boolean('is_lifetime')->default(false);
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->string('telegram_username', 64)->nullable();
            $table->timestamp('identity_linked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'product_id'], 'telegram_access_grants_order_product_unique');
            $table->index(['tenant_id', 'user_id']);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('telegram_access_communities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('telegram_access_grant_id')->constrained('telegram_access_grants')->cascadeOnDelete();
            $table->foreignId('telegram_community_id')->constrained('telegram_communities')->cascadeOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->string('status', 32)->default('pending')->index();
            $table->text('invite_link')->nullable();
            $table->timestamp('invite_link_expires_at')->nullable();
            $table->timestamp('invite_generated_at')->nullable();
            $table->timestamp('join_requested_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('failure_started_at')->nullable();
            $table->timestamp('failure_ended_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['telegram_access_grant_id', 'telegram_community_id'],
                'telegram_access_communities_grant_community_unique'
            );
            $table->index(['tenant_id', 'telegram_chat_id']);
            $table->index(['telegram_chat_id', 'status'], 'telegram_access_communities_chat_status_idx');
        });

        Schema::create('telegram_access_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('telegram_access_grant_id')->constrained('telegram_access_grants')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_access_community_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['telegram_access_grant_id', 'event_type']);
        });

        Schema::create('telegram_delivery_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('telegram_access_grant_id')->nullable()->index();
            $table->unsignedBigInteger('telegram_community_id')->nullable()->index();
            $table->string('idempotency_key', 120)->unique();
            $table->string('reason_code', 64)->index();
            $table->text('reason_detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('affected_user_ids')->nullable();
            $table->json('affected_community_ids')->nullable();
            $table->string('compensation_status', 32)->default('none')->index();
            $table->unsignedInteger('compensation_seconds')->nullable();
            $table->timestamp('compensation_authorized_at')->nullable();
            $table->timestamp('compensation_applied_at')->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_delivery_incidents');
        Schema::dropIfExists('telegram_access_events');
        Schema::dropIfExists('telegram_access_communities');
        Schema::dropIfExists('telegram_access_grants');
        Schema::dropIfExists('telegram_buyer_identities');
    }
};
