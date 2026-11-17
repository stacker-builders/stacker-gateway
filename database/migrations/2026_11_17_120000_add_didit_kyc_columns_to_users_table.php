<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'kyc_provider')) {
                $table->string('kyc_provider', 16)->nullable()->after('kyc_status');
            }
            if (! Schema::hasColumn('users', 'kyc_external_session_id')) {
                $table->string('kyc_external_session_id', 64)->nullable()->after('kyc_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'kyc_external_session_id')) {
                $table->dropColumn('kyc_external_session_id');
            }
            if (Schema::hasColumn('users', 'kyc_provider')) {
                $table->dropColumn('kyc_provider');
            }
        });
    }
};
