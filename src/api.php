<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);

    if (!is_array($data)) {
        json_response(['error' => 'JSON inválido'], 400);
    }

    return $data;
}

function require_n8n_key(): void
{
    $expected = env('N8N_API_KEY');

    if (!$expected) {
        json_response(['error' => 'N8N_API_KEY não configurada'], 500);
    }

    $received = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (!$received || !hash_equals($expected, $received)) {
        json_response(['error' => 'Não autorizado'], 401);
    }
}

function normalize_phone(?string $phone): string
{
    return preg_replace('/\D+/', '', $phone ?? '');
}
