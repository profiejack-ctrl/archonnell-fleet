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

// Forward Vercel requests to Laravel's index.php
require __DIR__ . '/../public/index.php';
