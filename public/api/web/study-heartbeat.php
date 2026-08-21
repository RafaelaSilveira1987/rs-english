<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/learning.php';

header('Content-Type: application/json; charset=utf-8');
$user = require_student();
$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$page = strtolower(trim((string)($payload['page'] ?? '')));
$allowed = ['vocabulary', 'corrections', 'diagnostic', 'onboarding'];
if (!in_array($page, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Página não contabilizável.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$seconds = max(15, min(120, (int)($payload['seconds'] ?? 0)));
$heartbeat = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($payload['heartbeat_id'] ?? '')) ?: 'unknown';
$sequence = max(1, (int)($payload['sequence'] ?? 1));

learning_record_event(
    db(),
    (string)$user['student_id'],
    learning_event_key('platform-study', [(string)$user['student_id'], $heartbeat, (string)$sequence]),
    'platform_study',
    'web_portal',
    null,
    null,
    $seconds,
    null,
    0,
    ['page' => $page, 'method' => 'active_heartbeat']
);

echo json_encode(['success' => true, 'seconds' => $seconds], JSON_UNESCAPED_UNICODE);
