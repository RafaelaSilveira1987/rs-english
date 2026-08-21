<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/learning.php';
require_once __DIR__ . '/../../../src/progress.php';

require_n8n_key();

$data = json_input();
$sessionId = trim((string)($data['session_id'] ?? ''));
$summary = trim((string)($data['summary'] ?? ''));
$summaryData = is_array($data['summary_data'] ?? null) ? $data['summary_data'] : [];
$reason = trim((string)($data['reason'] ?? 'teacher_finished'));

if ($sessionId === '') {
    json_response(['success' => false, 'error' => 'session_id é obrigatório'], 422);
}

$allowedReasons = ['teacher_finished', 'student_requested', 'max_turns_reached', 'timeout'];
if (!in_array($reason, $allowedReasons, true)) {
    $reason = 'teacher_finished';
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $query = $pdo->prepare(<<<'SQL'
        UPDATE sessions
        SET status = 'completed',
            ended_at = COALESCE(ended_at, NOW()),
            completed_reason = :reason,
            conversation_summary = NULLIF(:summary, ''),
            summary_data = CAST(:summary_data AS jsonb)
        WHERE id = :session_id
          AND mode = 'conversation'
        RETURNING id, student_id, channel, turn_count, max_turns,
                  conversation_topic, conversation_style, created_at, ended_at
    SQL);
    $query->execute([
        'reason' => $reason,
        'summary' => $summary,
        'summary_data' => learning_json($summaryData),
        'session_id' => $sessionId,
    ]);

    $session = $query->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Sessão de conversação não encontrada.'], 404);
    }

    $studentId = (string)$session['student_id'];
    $evaluation = is_array($data['evaluation'] ?? null)
        ? $data['evaluation']
        : (is_array($summaryData['evaluation'] ?? null) ? $summaryData['evaluation'] : $summaryData);

    $recordedSkills = learning_record_evaluation(
        $pdo,
        $studentId,
        $evaluation,
        [
            'source' => 'conversation_summary',
            'event_prefix' => learning_event_key('conversation-summary-skill', [$sessionId]),
            'session_id' => $sessionId,
            'message_type' => (string)($data['message_type'] ?? 'text'),
            'weight' => 1.5,
            'confidence' => $evaluation['confidence_score'] ?? null,
            'evidence_text' => $summary,
            'evidence_data' => [
                'topic' => $session['conversation_topic'],
                'turn_count' => (int)$session['turn_count'],
                'completed_reason' => $reason,
            ],
        ]
    );

    $corrections = $evaluation['corrections'] ?? $evaluation['errors'] ?? [];
    $correctionsSaved = is_array($corrections)
        ? learning_sync_corrections(
            $pdo,
            $studentId,
            $corrections,
            [
                'session_id' => $sessionId,
                'channel' => (string)($session['channel'] ?? 'whatsapp'),
                'event_prefix' => learning_event_key('conversation-summary-correction', [$sessionId]),
            ]
        )
        : 0;

    $eventScore = $recordedSkills !== []
        ? round(array_sum($recordedSkills) / count($recordedSkills), 2)
        : null;

    learning_record_event(
        $pdo,
        $studentId,
        learning_event_key('conversation-completed', [$sessionId]),
        'conversation_completed',
        (string)($session['channel'] ?? 'whatsapp'),
        $sessionId,
        $sessionId,
        0,
        $eventScore,
        0,
        [
            'reason' => $reason,
            'topic' => $session['conversation_topic'],
            'style' => $session['conversation_style'],
            'turn_count' => (int)$session['turn_count'],
            'max_turns' => (int)$session['max_turns'],
            'skills_recorded' => array_keys($recordedSkills),
            'corrections_saved' => $correctionsSaved,
        ]
    );

    $pdo->commit();
    progress_refresh_after_event($studentId);

    json_response([
        'success' => true,
        'session' => $session,
        'telemetry' => [
            'skills_recorded' => array_keys($recordedSkills),
            'corrections_saved' => $correctionsSaved,
            'event_score' => $eventScore,
        ],
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[END CONVERSATION] ' . $exception->getMessage());
    json_response(['success' => false, 'error' => 'Não foi possível encerrar a conversa.'], 500);
}
