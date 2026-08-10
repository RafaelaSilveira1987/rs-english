<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();

$data = json_input();

$phone = normalize_phone($data['phone'] ?? '');
$name = trim($data['student_name'] ?? 'Aluno');
$studentMessage = trim($data['student_message'] ?? '');
$teacherMessage = trim($data['teacher_message'] ?? '');
$messageType = trim($data['message_type'] ?? 'text');
$mode = trim($data['mode'] ?? 'conversation');
$topic = trim($data['topic'] ?? '');
$evaluation = is_array($data['evaluation'] ?? null) ? $data['evaluation'] : [];

if (!$phone || !$studentMessage) {
    json_response(['error' => 'phone e student_message são obrigatórios'], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $studentStmt = $pdo->prepare("SELECT id FROM students WHERE phone = :phone LIMIT 1");
    $studentStmt->execute(['phone' => $phone]);
    $studentId = $studentStmt->fetchColumn();

    if (!$studentId) {
        $createStudent = $pdo->prepare("
            INSERT INTO students (name, phone)
            VALUES (:name, :phone)
            RETURNING id
        ");
        $createStudent->execute(['name' => $name ?: 'Aluno', 'phone' => $phone]);
        $studentId = $createStudent->fetchColumn();

        $createProfile = $pdo->prepare("
            INSERT INTO student_profiles (student_id, overall_level, goal, correction_mode)
            VALUES (:student_id, 'A1', 'Aprender inglês', 'balanced')
        ");
        $createProfile->execute(['student_id' => $studentId]);
    }

    $sessionStmt = $pdo->prepare("
        SELECT id FROM sessions
        WHERE student_id = :student_id
          AND status = 'active'
          AND created_at >= NOW() - INTERVAL '4 hours'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $sessionStmt->execute(['student_id' => $studentId]);
    $sessionId = $sessionStmt->fetchColumn();

    if (!$sessionId) {
        $newSession = $pdo->prepare("
            INSERT INTO sessions (student_id, channel, mode, topic, status)
            VALUES (:student_id, 'whatsapp', :mode, :topic, 'active')
            RETURNING id
        ");
        $newSession->execute([
            'student_id' => $studentId,
            'mode' => $mode,
            'topic' => $topic ?: null,
        ]);
        $sessionId = $newSession->fetchColumn();
    }

    $studentMsgStmt = $pdo->prepare("
        INSERT INTO messages
        (session_id, student_id, role, message_type, content)
        VALUES (:session_id, :student_id, 'student', :message_type, :content)
        RETURNING id
    ");
    $studentMsgStmt->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
        'message_type' => $messageType,
        'content' => $studentMessage,
    ]);
    $studentMessageId = $studentMsgStmt->fetchColumn();

    if ($teacherMessage !== '') {
        $teacherMsgStmt = $pdo->prepare("
            INSERT INTO messages
            (session_id, student_id, role, message_type, content)
            VALUES (:session_id, :student_id, 'teacher', 'text', :content)
        ");
        $teacherMsgStmt->execute([
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'content' => $teacherMessage,
        ]);
    }

    foreach (($evaluation['errors'] ?? []) as $error) {
        if (!is_array($error)) continue;

        $errorStmt = $pdo->prepare("
            INSERT INTO student_errors
            (student_id, session_id, message_id, category, topic, original_text,
             corrected_text, explanation, severity, occurrences, status, next_review_at)
            VALUES
            (:student_id, :session_id, :message_id, :category, :topic, :original_text,
             :corrected_text, :explanation, :severity, 1, 'learning', NOW() + INTERVAL '2 days')
        ");

        $errorStmt->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'message_id' => $studentMessageId,
            'category' => $error['category'] ?? null,
            'topic' => $error['topic'] ?? null,
            'original_text' => $error['original'] ?? null,
            'corrected_text' => $error['corrected'] ?? null,
            'explanation' => $error['explanation'] ?? null,
            'severity' => $error['severity'] ?? 'medium',
        ]);
    }

    foreach (($evaluation['skills'] ?? []) as $skill) {
        if (!is_array($skill) || empty($skill['code'])) continue;

        $skillFind = $pdo->prepare("SELECT id FROM skills WHERE code = :code LIMIT 1");
        $skillFind->execute(['code' => $skill['code']]);
        $skillId = $skillFind->fetchColumn();

        if (!$skillId) continue;

        $score = max(0, min(100, (float)($skill['score'] ?? 0)));
        $success = !empty($skill['success']) ? 1 : 0;

        $upsertSkill = $pdo->prepare("
            INSERT INTO student_skills
            (student_id, skill_id, score, attempts, successes, last_practiced_at, updated_at)
            VALUES (:student_id, :skill_id, :score, 1, :successes, NOW(), NOW())
            ON CONFLICT (student_id, skill_id)
            DO UPDATE SET
                score = ROUND(((student_skills.score * 3) + EXCLUDED.score) / 4, 2),
                attempts = student_skills.attempts + 1,
                successes = student_skills.successes + EXCLUDED.successes,
                last_practiced_at = NOW(),
                updated_at = NOW()
        ");

        $upsertSkill->execute([
            'student_id' => $studentId,
            'skill_id' => $skillId,
            'score' => $score,
            'successes' => $success,
        ]);
    }

    $grammar = $evaluation['grammar_score'] ?? null;
    $vocabulary = $evaluation['vocabulary_score'] ?? null;
    $fluency = $evaluation['fluency_score'] ?? null;
    $comprehension = $evaluation['comprehension_score'] ?? null;

    $sessionUpdate = $pdo->prepare("
        UPDATE sessions SET
            grammar_score = COALESCE(:grammar, grammar_score),
            vocabulary_score = COALESCE(:vocabulary, vocabulary_score),
            fluency_score = COALESCE(:fluency, fluency_score),
            comprehension_score = COALESCE(:comprehension, comprehension_score)
        WHERE id = :id
    ");
    $sessionUpdate->execute([
        'grammar' => $grammar,
        'vocabulary' => $vocabulary,
        'fluency' => $fluency,
        'comprehension' => $comprehension,
        'id' => $sessionId,
    ]);

    $profileUpdate = $pdo->prepare("
        UPDATE student_profiles SET
            grammar_score = COALESCE(:grammar, grammar_score),
            vocabulary_score = COALESCE(:vocabulary, vocabulary_score),
            fluency_score = COALESCE(:fluency, fluency_score),
            last_study_at = NOW(),
            xp = xp + 5,
            updated_at = NOW()
        WHERE student_id = :student_id
    ");
    $profileUpdate->execute([
        'grammar' => $grammar,
        'vocabulary' => $vocabulary,
        'fluency' => $fluency,
        'student_id' => $studentId,
    ]);

    $pdo->commit();

    json_response([
        'success' => true,
        'student_id' => $studentId,
        'session_id' => $sessionId,
    ], 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
