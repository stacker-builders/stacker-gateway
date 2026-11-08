<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_access_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('latest_order_id')->nullable()->after('order_id')->index();
            $table->unsignedInteger('renewal_count')->default(0)->after('is_lifetime');
            $table->timestamp('last_renewed_at')->nullable()->after('renewal_count');
            $table->timestamp('expired_at')->nullable()->after('last_renewed_at');
            $table->timestamp('revoked_at')->nullable()->after('expired_at');
            $table->string('revoked_reason', 64)->nullable()->after('revoked_at');
            $table->text('revoked_detail')->nullable()->after('revoked_reason');
        });

        Schema::create('telegram_access_order_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('telegram_access_grant_id')->constrained('telegram_access_grants')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('link_type', 32)->default('purchase'); // purchase|renewal|extension
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['telegram_access_grant_id', 'order_id']);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('telegram_access_compensations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('telegram_access_grant_id')->constrained('telegram_access_grants')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_delivery_incident_id')->nullable()->index();
            $table->string('idempotency_key', 120)->unique();
            $table->unsignedInteger('seconds_added');
            $table->timestamp('previous_ends_at')->nullable();
            $table->timestamp('new_ends_at')->nullable();
            $table->string('status', 32)->default('applied');
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_access_compensations');
        Schema::dropIfExists('telegram_access_order_links');

        Schema::table('telegram_access_grants', function (Blueprint $table) {
            $table->dropColumn([
                'latest_order_id',
                'renewal_count',
                'last_renewed_at',
                'expired_at',
                'revoked_at',
                'revoked_reason',
                'revoked_detail',
            ]);
        });
    }
};
