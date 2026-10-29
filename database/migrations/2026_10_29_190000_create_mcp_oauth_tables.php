<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_oauth_clients')) {
            Schema::create('mcp_oauth_clients', function (Blueprint $table) {
                $table->id();
                $table->string('client_id', 64)->unique();
                $table->string('client_name')->nullable();
                $table->json('redirect_uris');
                $table->string('token_endpoint_auth_method', 32)->default('none');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mcp_oauth_access_tokens')) {
            Schema::create('mcp_oauth_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('client_id', 64)->index();
                $table->string('public_id', 16)->unique();
                $table->string('token_hash');
                $table->string('token_suffix', 8);
                $table->string('refresh_public_id', 16)->nullable()->unique();
                $table->string('refresh_hash')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('refresh_expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_access_tokens');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
