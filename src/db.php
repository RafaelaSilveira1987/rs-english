<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST');
    $port = env('DB_PORT', '5432');
    $name = env('DB_NAME', 'rs_english');
    $user = env('DB_USER');
    $pass = env('DB_PASSWORD');

    if (!$host || !$user || !$pass) {
        throw new RuntimeException('Configuração do banco incompleta.');
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
