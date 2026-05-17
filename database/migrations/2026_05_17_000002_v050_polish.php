<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.5.0: per-folder advanced rclone overrides (F5.3) and a usage
 * snapshot table for storage trends (F5.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rnvsync_sync_folders', function (Blueprint $table) {
            $table->unsignedInteger('transfers')->nullable()->after('sync_mode');
            $table->unsignedInteger('checkers')->nullable()->after('transfers');
            $table->string('chunk_size')->nullable()->after('checkers');
        });

        Schema::create('rnvsync_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()
                ->constrained('rnvsync_accounts')->nullOnDelete();
            $table->bigInteger('cloud_used_bytes')->default(0);
            $table->bigInteger('cloud_total_bytes')->default(0);
            $table->bigInteger('cache_used_bytes')->default(0);
            $table->date('captured_on');
            $table->timestamps();
            $table->unique(['account_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rnvsync_usage_snapshots');
        Schema::table('rnvsync_sync_folders', function (Blueprint $table) {
            $table->dropColumn(['transfers', 'checkers', 'chunk_size']);
        });
    }
};
