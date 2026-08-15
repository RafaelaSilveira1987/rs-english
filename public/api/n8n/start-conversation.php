<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/conversation.php';

require_n8n_key();

$data = json_input();

$phone = normalize_phone($data['phone'] ?? '');
$name = trim((string)($data['student_name'] ?? 'Aluno'));
$topic = conversation_topic((string)($data['topic'] ?? 'daily_life'));
$style = conversation_style((string)($data['style'] ?? 'guided'));
$maxTurns = conversation_max_turns($data['max_turns'] ?? 10);
$channel = trim((string)($data['channel'] ?? 'whatsapp'));

if ($phone === '') {
    json_response([
        'success' => false,
        'error' => 'phone é obrigatório',
    ], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $query = $pdo->prepare("
        SELECT id
        FROM students
        WHERE regexp_replace(
            COALESCE(phone, ''),
            '[^0-9]',
            '',
            'g'
        ) = :phone
        LIMIT 1
    ");
    $query->execute(['phone' => $phone]);
    $studentId = $query->fetchColumn();

    if (!$studentId) {
        $query = $pdo->prepare("
            INSERT INTO students(name, phone)
            VALUES(:name, :phone)
            RETURNING id
        ");
        $query->execute([
            'name' => $name !== '' ? $name : 'Aluno',
            'phone' => $phone,
        ]);
        $studentId = $query->fetchColumn();

        $pdo->prepare("
            INSERT INTO student_profiles(
                student_id,
                overall_level,
                estimated_level,
                goal,
                correction_mode,
                diagnostic_status,
                diagnostic_step,
                preferred_language_support,
                pre_a1
            )
            VALUES(
                :student_id,
                'PRE-A1',
                'PRE-A1',
                'Aprender inglês',
                'balanced',
                'pending',
                0,
                'portuguese',
                TRUE
            )
        ")->execute(['student_id' => $studentId]);
    }

    $pdo->prepare("
        UPDATE sessions
        SET
            status = 'completed',
            ended_at = NOW(),
            completed_reason = 'replaced_by_new_session'
        WHERE student_id = :student_id
          AND mode = 'conversation'
          AND status = 'active'
    ")->execute(['student_id' => $studentId]);

    $query = $pdo->prepare("
        INSERT INTO sessions(
            student_id,
            channel,
            mode,
            topic,
            status,
            turn_count,
            max_turns,
            conversation_topic,
            conversation_style
        )
        VALUES(
            :student_id,
            :channel,
            'conversation',
            :topic,
            'active',
            0,
            :max_turns,
            :conversation_topic,
            :conversation_style
        )
        RETURNING id
    ");
    $query->execute([
        'student_id' => $studentId,
        'channel' => $channel !== '' ? $channel : 'whatsapp',
        'topic' => $topic,
        'max_turns' => $maxTurns,
        'conversation_topic' => $topic,
        'conversation_style' => $style,
    ]);

    $sessionId = $query->fetchColumn();

    $pdo->commit();

    json_response([
        'success' => true,
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'conversation' => [
            'topic' => $topic,
            'style' => $style,
            'turn_count' => 0,
            'max_turns' => $maxTurns,
            'remaining_turns' => $maxTurns,
            'should_wrap_up' => false,
            'completed' => false,
        ],
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[START CONVERSATION] ' . $exception->getMessage());

    json_response([
        'success' => false,
        'error' => 'Não foi possível iniciar a conversa.',
    ], 500);
}
