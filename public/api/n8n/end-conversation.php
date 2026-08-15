<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();

$data = json_input();

$sessionId = trim((string)($data['session_id'] ?? ''));
$summary = trim((string)($data['summary'] ?? ''));
$summaryData = is_array($data['summary_data'] ?? null)
    ? $data['summary_data']
    : [];
$reason = trim((string)($data['reason'] ?? 'teacher_finished'));

if ($sessionId === '') {
    json_response([
        'success' => false,
        'error' => 'session_id é obrigatório',
    ], 422);
}

$allowedReasons = [
    'teacher_finished',
    'student_requested',
    'max_turns_reached',
    'timeout',
];

if (!in_array($reason, $allowedReasons, true)) {
    $reason = 'teacher_finished';
}

$pdo = db();

try {
    $query = $pdo->prepare("
        UPDATE sessions
        SET
            status = 'completed',
            ended_at = NOW(),
            completed_reason = :reason,
            conversation_summary = NULLIF(:summary, ''),
            summary_data = CAST(:summary_data AS jsonb)
        WHERE id = :session_id
          AND mode = 'conversation'
        RETURNING
            id,
            student_id,
            turn_count,
            max_turns,
            conversation_topic,
            conversation_style
    ");
    $query->execute([
        'reason' => $reason,
        'summary' => $summary,
        'summary_data' => json_encode(
            $summaryData,
            JSON_UNESCAPED_UNICODE
        ),
        'session_id' => $sessionId,
    ]);

    $session = $query->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        json_response([
            'success' => false,
            'error' => 'Sessão de conversação não encontrada.',
        ], 404);
    }

    json_response([
        'success' => true,
        'session' => $session,
    ]);
} catch (Throwable $exception) {
    error_log('[END CONVERSATION] ' . $exception->getMessage());

    json_response([
        'success' => false,
        'error' => 'Não foi possível encerrar a conversa.',
    ], 500);
}
