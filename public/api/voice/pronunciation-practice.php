<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/audio.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/corrections.php';
require_once __DIR__ . '/../../../src/learning.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_student();
$pdo = db();

if (empty($_FILES['audio']['tmp_name'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Áudio obrigatório.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$target = trim((string)($_POST['target_text'] ?? ''));
if ($target === '') {
    http_response_code(422);
    echo json_encode(['error' => 'target_text é obrigatório.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    $mime = (string)($_FILES['audio']['type'] ?? 'audio/webm');
    $duration = max(0.0, (float)($_POST['duration_seconds'] ?? 0));
    $transcription = transcribe_audio_file($_FILES['audio']['tmp_name'], $mime);
    $comparison = compare_pronunciation_words($target, $transcription['text']);
    $feedback = build_pronunciation_feedback($comparison);
    $speech = synthesize_speech($target, 'coral', 0.9, 'mp3');
    $savedVoice = save_voice_bytes($speech['bytes'], 'mp3');

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO pronunciation_attempts(
            student_id, target_text, transcription, similarity_score,
            matched_words, missing_words, unexpected_words,
            audio_mime, audio_duration_seconds, feedback, model_audio_path
        ) VALUES(
            :student_id, :target_text, :transcription, :similarity_score,
            CAST(:matched_words AS jsonb), CAST(:missing_words AS jsonb),
            CAST(:unexpected_words AS jsonb), :audio_mime, :duration,
            :feedback, :model_audio_path
        )
        RETURNING id
    SQL);
    $stmt->execute([
        'student_id' => $user['student_id'],
        'target_text' => $target,
        'transcription' => $transcription['text'],
        'similarity_score' => $comparison['similarity_score'],
        'matched_words' => learning_json($comparison['matched_words']),
        'missing_words' => learning_json($comparison['missing_words']),
        'unexpected_words' => learning_json($comparison['unexpected_words']),
        'audio_mime' => $mime,
        'duration' => $duration ?: null,
        'feedback' => $feedback,
        'model_audio_path' => $savedVoice['relative'],
    ]);
    $attemptId = (string)$stmt->fetchColumn();

    learning_record_skill_evidence(
        $pdo,
        (string)$user['student_id'],
        learning_event_key('pronunciation-skill', [$attemptId]),
        'pronunciation_practice',
        'pronunciation',
        $comparison['similarity_score'],
        2.5,
        null,
        $transcription['text'],
        [
            'message_type' => 'audio',
            'target_text' => $target,
            'matched_words' => $comparison['matched_words'],
            'missing_words' => $comparison['missing_words'],
            'unexpected_words' => $comparison['unexpected_words'],
        ]
    );
    learning_recalculate_profile_skills($pdo, (string)$user['student_id']);

    learning_record_event(
        $pdo,
        (string)$user['student_id'],
        learning_event_key('pronunciation', [$attemptId]),
        'pronunciation_practice',
        'web_voice',
        null,
        $attemptId,
        max(0, (int)round($duration)),
        $comparison['similarity_score'],
        3,
        [
            'target_text' => $target,
            'transcription' => $transcription['text'],
        ]
    );

    $pdo->prepare(<<<'SQL'
        UPDATE student_profiles
        SET xp = COALESCE(xp, 0) + 3,
            last_study_at = NOW(),
            updated_at = NOW()
        WHERE student_id = :student_id
    SQL)->execute(['student_id' => $user['student_id']]);

    $pdo->commit();
    progress_refresh_after_event((string)$user['student_id']);

    echo json_encode([
        'ok' => true,
        'attempt_id' => $attemptId,
        'target_text' => $target,
        'transcription' => $transcription['text'],
        'similarity_score' => $comparison['similarity_score'],
        'matched_words' => $comparison['matched_words'],
        'missing_words' => $comparison['missing_words'],
        'unexpected_words' => $comparison['unexpected_words'],
        'feedback' => $feedback,
        'model_audio_url' => $savedVoice['url'],
        'notice' => 'Comparação entre frase-alvo e transcrição; não é avaliação fonética clínica.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
