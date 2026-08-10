<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/api.php';

try {
    $result = db()->query("SELECT NOW() AS database_time")->fetch();

    json_response([
        'status' => 'ok',
        'service' => 'rs-english-php',
        'database' => 'connected',
        'database_time' => $result['database_time'],
    ]);
} catch (Throwable $e) {
    json_response([
        'status' => 'error',
        'database' => 'disconnected',
        'message' => $e->getMessage(),
    ], 500);
}
