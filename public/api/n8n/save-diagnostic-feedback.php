<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

require_n8n_key();

$data = json_input();
$studentId = trim((string)($data['student_id'] ?? ''));
$level = strtoupper(trim((string)($data['estimated_level'] ?? 'PRE-A1')));
$feedback = trim((string)($data['written_feedback'] ?? ''));
$scores = is_array($data['scores'] ?? null) ? $data['scores'] : [];

$allowedLevels = ['PRE-A1', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
if (!in_array($level, $allowedLevels, true)) {
    $level = 'PRE-A1';
}

if ($studentId === '' || $feedback === '') {
    json_response(['ok' => false, 'error' => 'student_id e written_feedback são obrigatórios.'], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $query = $pdo->prepare(<<<'SQL'
        INSERT INTO diagnostic_reports(
            student_id, estimated_level, confidence_score,
            strengths, weaknesses, detected_goals, written_feedback,
            study_plan, first_activity, scores, cefr_evidence,
            recommendations, raw_payload, delivered_at,
            delivery_channel, delivery_message_id
        ) VALUES(
            :student_id, :estimated_level, :confidence_score,
            CAST(:strengths AS jsonb), CAST(:weaknesses AS jsonb),
            CAST(:detected_goals AS jsonb), :written_feedback,
            CAST(:study_plan AS jsonb), CAST(:first_activity AS jsonb),
            CAST(:scores AS jsonb), CAST(:cefr_evidence AS jsonb),
            CAST(:recommendations AS jsonb), CAST(:raw_payload AS jsonb),
            CASE WHEN CAST(:delivered AS boolean) THEN NOW() ELSE NULL END,
            :delivery_channel, :delivery_message_id
        )
        RETURNING id
    SQL);
    $query->execute([
        'student_id' => $studentId,
        'estimated_level' => $level,
        'confidence_score' => $data['confidence_score'] ?? null,
        'strengths' => learning_json(is_array($data['strengths'] ?? null) ? $data['strengths'] : []),
        'weaknesses' => learning_json(is_array($data['weaknesses'] ?? null) ? $data['weaknesses'] : []),
        'detected_goals' => learning_json(is_array($data['detected_goals'] ?? null) ? $data['detected_goals'] : []),
        'written_feedback' => $feedback,
        'study_plan' => learning_json(is_array($data['study_plan'] ?? null) ? $data['study_plan'] : []),
        'first_activity' => learning_json(is_array($data['first_activity'] ?? null) ? $data['first_activity'] : []),
        'scores' => learning_json($scores),
        'cefr_evidence' => learning_json(is_array($data['cefr_evidence'] ?? null) ? $data['cefr_evidence'] : []),
        'recommendations' => learning_json(is_array($data['recommendations'] ?? null) ? $data['recommendations'] : []),
        'raw_payload' => learning_json($data),
        'delivered' => !empty($data['delivered']) ? 'true' : 'false',
        'delivery_channel' => $data['delivery_channel'] ?? null,
        'delivery_message_id' => $data['delivery_message_id'] ?? null,
    ]);
    $reportId = (string)$query->fetchColumn();

    $query = $pdo->prepare(<<<'SQL'
        UPDATE student_profiles
        SET estimated_level = :level,
            overall_level = :level,
            diagnostic_status = 'completed',
            pre_a1 = CAST(:pre_a1 AS boolean),
            diagnostic_completed_at = NOW(),
            last_study_at = NOW(),
            updated_at = NOW()
        WHERE student_id = :student_id
    SQL);
    $query->execute([
        'level' => $level,
        'pre_a1' => $level === 'PRE-A1' ? 'true' : 'false',
        'student_id' => $studentId,
    ]);

    $recordedSkills = learning_record_evaluation(
        $pdo,
        $studentId,
        [
            'scores' => $scores,
            'confidence_score' => $data['confidence_score'] ?? null,
        ],
        [
            'source' => 'diagnostic_feedback',
            'event_prefix' => learning_event_key('diagnostic-feedback-skill', [$reportId]),
            'source_id' => $reportId,
            'weight' => 5.0,
            'confidence' => $data['confidence_score'] ?? null,
            'evidence_text' => $feedback,
            'evidence_data' => ['official_level' => $level],
        ]
    );

    learning_record_event(
        $pdo,
        $studentId,
        learning_event_key('diagnostic-feedback', [$reportId]),
        'diagnostic_completed',
        (string)($data['delivery_channel'] ?? 'system'),
        null,
        $reportId,
        0,
        $recordedSkills !== [] ? round(array_sum($recordedSkills) / count($recordedSkills), 2) : null,
        25,
        [
            'official_level' => $level,
            'skills_recorded' => array_keys($recordedSkills),
        ]
    );

    $pdo->commit();
    progress_refresh_after_event($studentId);

    json_response([
        'ok' => true,
        'report_id' => $reportId,
        'official_level' => $level,
        'skills_recorded' => array_keys($recordedSkills),
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[RS ENGLISH DIAGNOSTIC FEEDBACK] ' . $exception->getMessage());

    $response = ['ok' => false, 'error' => 'Não foi possível salvar a devolutiva do diagnóstico.'];
    if ((string)env('APP_ENV', 'production') !== 'production') {
        $response['details'] = $exception->getMessage();
    }

    json_response($response, 500);
}
