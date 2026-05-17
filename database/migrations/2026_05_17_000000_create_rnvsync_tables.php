<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain schema for RNV Sync (SPEC §7). All tables prefixed `rnvsync_`
 * (the original spec used `cirrus_`; renamed with the project).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rnvsync_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider')->default('onedrive_personal');
            $table->string('remote_name');
            $table->string('email')->nullable();
            $table->text('oauth_token')->nullable(); // Crypt-encrypted token JSON
            $table->string('status')->default('active');
            $table->bigInteger('quota_total_bytes')->nullable();
            $table->bigInteger('quota_used_bytes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rnvsync_sync_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('rnvsync_accounts')->cascadeOnDelete();
            $table->string('remote_path');
            $table->string('local_path');
            $table->string('sync_mode')->default('bisync');
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->timestamps();
        });

        Schema::create('rnvsync_file_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('rnvsync_accounts')->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_directory')->default(false);
            $table->string('policy')->default('default');
            $table->timestamps();
            $table->unique(['account_id', 'path']);
        });

        Schema::create('rnvsync_sync_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('rnvsync_accounts')->cascadeOnDelete();
            $table->foreignId('sync_folder_id')->nullable()
                ->constrained('rnvsync_sync_folders')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('running');
            $table->integer('files_transferred')->default(0);
            $table->bigInteger('bytes_transferred')->default(0);
            $table->integer('errors_count')->default(0);
            $table->string('log_path')->nullable();
        });

        Schema::create('rnvsync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('rnvsync_accounts')->cascadeOnDelete();
            $table->string('path');
            $table->timestamp('local_modified_at')->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->bigInteger('local_size_bytes')->nullable();
            $table->bigInteger('remote_size_bytes')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
        });

        Schema::create('rnvsync_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable(); // JSON-encoded
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('rnvsync_mount_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('rnvsync_accounts')->cascadeOnDelete();
            $table->string('mount_point');
            $table->integer('pid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->string('status')->default('stopped');
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rnvsync_mount_processes');
        Schema::dropIfExists('rnvsync_settings');
        Schema::dropIfExists('rnvsync_conflicts');
        Schema::dropIfExists('rnvsync_sync_history');
        Schema::dropIfExists('rnvsync_file_policies');
        Schema::dropIfExists('rnvsync_sync_folders');
        Schema::dropIfExists('rnvsync_accounts');
    }
};
