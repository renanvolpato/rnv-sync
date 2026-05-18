<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts onboarded via rclone's own OAuth (zero-config "easy" mode)
 * carry a token issued to rclone's built-in OneDrive client. Their
 * generated rclone remote must therefore NOT pin a client_id, so rclone
 * uses the same built-in client the token belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rnvsync_accounts', function (Blueprint $table) {
            $table->boolean('uses_bundled_client')->default(false)->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('rnvsync_accounts', function (Blueprint $table) {
            $table->dropColumn('uses_bundled_client');
        });
    }
};
