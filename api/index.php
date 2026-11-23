<?php

// Ensure serverless temporary storage directories exist for blade views, cache, and sessions
$tmpDirs = [
    '/tmp/views',
    '/tmp/cache',
    '/tmp/sessions',
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
