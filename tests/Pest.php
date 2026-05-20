<?php

use App\Services\Files\PendingOps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

// Tests share the live storage/app/* JSON state files with the running
// app. Without this, a test that calls PendingOps::mark() leaks an
// entry into the running tray indicator's state and can pin it on
// "syncing…" forever. Reset before and after every Feature test.
uses()->beforeEach(function () {
    File::ensureDirectoryExists(storage_path('app'));
    File::put(PendingOps::file(), '[]');
    File::put(storage_path('app/rnvsync-errors.json'), '{}');
})->afterEach(function () {
    File::put(PendingOps::file(), '[]');
    File::put(storage_path('app/rnvsync-errors.json'), '{}');
})->in('Feature');
