<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.4.0: OneDrive Business / SharePoint need an explicit drive_id and
 * drive_type, plus the tenant for Business accounts (SPEC F4.1 EARS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rnvsync_accounts', function (Blueprint $table) {
            $table->string('drive_id')->nullable()->after('remote_name');
            $table->string('drive_type')->nullable()->after('drive_id');
            $table->string('tenant_id')->nullable()->after('drive_type');
        });
    }

    public function down(): void
    {
        Schema::table('rnvsync_accounts', function (Blueprint $table) {
            $table->dropColumn(['drive_id', 'drive_type', 'tenant_id']);
        });
    }
};
