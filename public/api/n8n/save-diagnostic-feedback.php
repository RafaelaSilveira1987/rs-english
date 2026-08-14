<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();

$data = json_input();
$studentId = trim((string)($data['student_id'] ?? ''));
$level = strtoupper(trim((string)($data['estimated_level'] ?? 'PRE-A1')));
$feedback = trim((string)($data['written_feedback'] ?? ''));

$allowedLevels = ['PRE-A1', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
if (!in_array($level, $allowedLevels, true)) {
    $level = 'PRE-A1';
}

if ($studentId === '' || $feedback === '') {
    json_response([
        'ok' => false,
        'error' => 'student_id e written_feedback são obrigatórios.',
    ], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $query = $pdo->prepare("
        INSERT INTO diagnostic_reports (
            student_id,
            estimated_level,
            confidence_score,
            strengths,
            weaknesses,
            detected_goals,
            written_feedback,
            study_plan,
            first_activity,
            delivered_at,
            delivery_channel,
            delivery_message_id
        )
        VALUES (
            :student_id,
            :estimated_level,
            :confidence_score,
            CAST(:strengths AS jsonb),
            CAST(:weaknesses AS jsonb),
            CAST(:detected_goals AS jsonb),
            :written_feedback,
            CAST(:study_plan AS jsonb),
            CAST(:first_activity AS jsonb),
            CASE WHEN :delivered THEN NOW() ELSE NULL END,
            :delivery_channel,
            :delivery_message_id
        )
        RETURNING id
    ");
    $query->execute([
        'student_id' => $studentId,
        'estimated_level' => $level,
        'confidence_score' => $data['confidence_score'] ?? null,
        'strengths' => json_encode($data['strengths'] ?? [], JSON_UNESCAPED_UNICODE),
        'weaknesses' => json_encode($data['weaknesses'] ?? [], JSON_UNESCAPED_UNICODE),
        'detected_goals' => json_encode($data['detected_goals'] ?? [], JSON_UNESCAPED_UNICODE),
        'written_feedback' => $feedback,
        'study_plan' => json_encode($data['study_plan'] ?? [], JSON_UNESCAPED_UNICODE),
        'first_activity' => json_encode($data['first_activity'] ?? [], JSON_UNESCAPED_UNICODE),
        'delivered' => !empty($data['delivered']),
        'delivery_channel' => $data['delivery_channel'] ?? null,
        'delivery_message_id' => $data['delivery_message_id'] ?? null,
    ]);
    $reportId = $query->fetchColumn();

    $query = $pdo->prepare("
        UPDATE student_profiles
        SET
            estimated_level = :level,
            overall_level = :level,
            diagnostic_status = 'completed',
            pre_a1 = :pre_a1,
            diagnostic_completed_at = NOW(),
            updated_at = NOW()
        WHERE student_id = :student_id
    ");
    $query->execute([
        'level' => $level,
        'pre_a1' => $level === 'PRE-A1',
        'student_id' => $studentId,
    ]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'report_id' => $reportId,
        'official_level' => $level,
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[RS ENGLISH DIAGNOSTIC FEEDBACK] ' . $exception->getMessage());

    $response = [
        'ok' => false,
        'error' => 'Não foi possível salvar a devolutiva do diagnóstico.',
    ];

    if ((string)env('APP_ENV', 'production') !== 'production') {
        $response['details'] = $exception->getMessage();
    }

    json_response($response, 500);
}
