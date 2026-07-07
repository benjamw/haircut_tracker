<?php

declare(strict_types=1);

// Test bootstrap: mirror the runtime timezone setup (index.php does the same)
// so PHP and MySQL agree on "now" in tests, just like in the running app.
require __DIR__ . '/../vendor/autoload.php';

App\Support\Dotenv::load(__DIR__ . '/../.env');
date_default_timezone_set(getenv('SHOP_TZ') ?: 'America/Denver');
