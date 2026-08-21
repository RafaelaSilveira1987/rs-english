<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/progress.php';

require_n8n_key();

$data = json_input();
$pdo = db();

$studentId = trim((string)($data['student_id'] ?? ''));
$phone = normalize_phone($data['phone'] ?? '');
$sessionId = trim((string)($data['session_id'] ?? '')) ?: null;
$channel = trim((string)($data['channel'] ?? 'unknown')) ?: 'unknown';

$corrections = $data['corrections']
    ?? ($data['evaluation']['corrections'] ?? null)
    ?? ($data['diagnostic']['corrections'] ?? []);

if (!is_array($corrections)) {
    json_response([
        'ok' => false,
        'error' => 'corrections precisa ser uma lista.',
    ], 422);
}

if ($studentId === '' && $phone !== '') {
    $query = $pdo->prepare("
        SELECT id
        FROM students
        WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
        LIMIT 1
    ");
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

    $insert = $pdo->prepare("
        INSERT INTO correction_events (
            student_id,
            session_id,
            channel,
            correction_type,
            original_text,
            corrected_text,
            explanation,
            target_word,
            detected_word,
            confidence_score,
            accepted
        )
        VALUES (
            :student_id,
            :session_id,
            :channel,
            :correction_type,
            :original_text,
            :corrected_text,
            :explanation,
            :target_word,
            :detected_word,
            :confidence_score,
            :accepted
        )
    ");

    $saved = 0;

    foreach (array_slice($corrections, 0, 10) as $correction) {
        if (!is_array($correction)) {
            continue;
        }

        $originalText = trim((string)($correction['original_text'] ?? ''));
        $correctedText = trim((string)($correction['corrected_text'] ?? ''));

        if ($originalText === '' && $correctedText === '') {
            continue;
        }

        $confidence = isset($correction['confidence_score'])
            ? max(0, min(100, (float)$correction['confidence_score']))
            : null;

        $insert->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'channel' => substr($channel, 0, 30),
            'correction_type' => substr((string)($correction['correction_type'] ?? 'written'), 0, 30),
            'original_text' => $originalText !== '' ? $originalText : null,
            'corrected_text' => $correctedText !== '' ? $correctedText : null,
            'explanation' => $correction['explanation'] ?? null,
            'target_word' => $correction['target_word'] ?? null,
            'detected_word' => $correction['detected_word'] ?? null,
            'confidence_score' => $confidence,
            'accepted' => array_key_exists('accepted', $correction)
                ? (bool)$correction['accepted']
                : true,
        ]);
        $saved++;
    }

    $pdo->commit();
    progress_refresh_after_event((string)$studentId);

    json_response([
        'ok' => true,
        'saved' => $saved,
        'student_id' => $studentId,
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
