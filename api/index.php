<?php

// Ensure serverless temporary storage directories exist for blade views, cache, sessions, and uploads
$tmpDirs = [
    '/tmp/views',
    '/tmp/cache',
    '/tmp/sessions',
    '/tmp/storage',
    '/tmp/storage/app',
    '/tmp/storage/app/public',
    '/tmp/storage/app/private',
    '/tmp/storage/app/public/trip-expenses',
    '/tmp/storage/app/public/trip-expenses/receipts',
    '/tmp/storage/app/public/fuel-logs',
    '/tmp/storage/app/public/fuel-logs/receipts',
    '/tmp/storage/app/private/fuel-logs',
    '/tmp/storage/app/private/fuel-logs/proofs',
];

foreach ($tmpDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Ensure writable SQLite database exists in /tmp for serverless environment
$tmpDb = '/tmp/database.sqlite';
if (!file_exists($tmpDb) || filesize($tmpDb) === 0) {
    $seedDb = __DIR__ . '/../database/database.sqlite';
    if (file_exists($seedDb) && filesize($seedDb) > 0) {
        @copy($seedDb, $tmpDb);
    } else {
        @touch($tmpDb);
    }
}
if (file_exists($tmpDb)) {
    @chmod($tmpDb, 0666);
}

// Forward Vercel requests to Laravel's index.php
require __DIR__ . '/../public/index.php';
