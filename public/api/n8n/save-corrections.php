<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

require_n8n_key();

$data = json_input();
$pdo = db();

$studentId = trim((string)($data['student_id'] ?? ''));
$phone = normalize_phone($data['phone'] ?? '');
$sessionId = trim((string)($data['session_id'] ?? '')) ?: null;
$channel = trim((string)($data['channel'] ?? 'unknown')) ?: 'unknown';
$requestId = trim((string)($data['request_id'] ?? $data['event_id'] ?? ''));

$corrections = $data['corrections']
    ?? ($data['evaluation']['corrections'] ?? null)
    ?? ($data['evaluation']['errors'] ?? null)
    ?? ($data['diagnostic']['corrections'] ?? []);

if (!is_array($corrections)) {
    json_response([
        'ok' => false,
        'error' => 'corrections precisa ser uma lista.',
    ], 422);
}

if ($studentId === '' && $phone !== '') {
    $query = $pdo->prepare(<<<'SQL'
        SELECT id
        FROM students
        WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
        LIMIT 1
    SQL);
    $query->execute(['phone' => $phone]);
    $studentId = (string)($query->fetchColumn() ?: '');
}

if ($studentId === '') {
    json_response([
        'ok' => false,
        'error' => 'student_id ou phone é obrigatório.',
    ], 422);
}

try {
    $pdo->beginTransaction();

    $payloadHash = substr(hash('sha256', learning_json($corrections)), 0, 20);
    $eventPrefix = learning_event_key('correction-batch', [
        $requestId !== '' ? $requestId : $payloadHash,
        $studentId,
        $sessionId ?? 'none',
    ]);

    $saved = learning_sync_corrections(
        $pdo,
        $studentId,
        $corrections,
        [
            'session_id' => $sessionId,
            'channel' => $channel,
            'event_prefix' => $eventPrefix,
        ]
    );

    if ($saved > 0) {
        learning_record_event(
            $pdo,
            $studentId,
            $eventPrefix,
            'corrections_registered',
            $channel,
            $sessionId,
            null,
            0,
            null,
            0,
            [
                'saved' => $saved,
                'received' => count($corrections),
            ]
        );
    }

    $pdo->commit();
    progress_refresh_after_event($studentId);

    json_response([
        'ok' => true,
        'saved' => $saved,
        'student_id' => $studentId,
        'telemetry_event' => $saved > 0 ? $eventPrefix : null,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[RS ENGLISH CORRECTIONS] ' . $exception->getMessage());

    $response = [
        'ok' => false,
        'error' => 'Não foi possível salvar as correções.',
    ];

    if ((string)env('APP_ENV', 'production') !== 'production') {
        $response['details'] = $exception->getMessage();
    }

    json_response($response, 500);
}
