<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();

$phone = normalize_phone($_GET['phone'] ?? '');

if (!$phone) {
    json_response(['error' => 'phone é obrigatório'], 422);
}

$pdo = db();

$stmt = $pdo->prepare("
SELECT
    s.id,
    s.name,
    s.phone,
    s.email,
    COALESCE(sp.overall_level,'A1') overall_level,
    sp.goal,
    COALESCE(sp.correction_mode,'balanced') correction_mode,
    COALESCE(sp.grammar_score,0) grammar_score,
    COALESCE(sp.vocabulary_score,0) vocabulary_score,
    COALESCE(sp.speaking_score,0) speaking_score,
    COALESCE(sp.listening_score,0) listening_score,
    COALESCE(sp.reading_score,0) reading_score,
    COALESCE(sp.writing_score,0) writing_score,
    COALESCE(sp.fluency_score,0) fluency_score,
    COALESCE(sp.pronunciation_score,0) pronunciation_score
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id = s.id
WHERE s.phone = :phone
LIMIT 1
");
$stmt->execute(['phone' => $phone]);
$student = $stmt->fetch();

if (!$student) {
    json_response([
        'found' => false,
        'phone' => $phone,
    ]);
}

$errorsStmt = $pdo->prepare("
SELECT category, topic, original_text, corrected_text, explanation, severity, occurrences
FROM student_errors
WHERE student_id = :student_id
  AND status = 'learning'
ORDER BY occurrences DESC, created_at DESC
LIMIT 10
");
$errorsStmt->execute(['student_id' => $student['id']]);

$vocabStmt = $pdo->prepare("
SELECT v.word, v.translation, sv.status, sv.mastery_score, sv.next_review_at
FROM student_vocabulary sv
JOIN vocabulary v ON v.id = sv.vocabulary_id
WHERE sv.student_id = :student_id
  AND sv.status = 'learning'
ORDER BY sv.next_review_at NULLS LAST
LIMIT 15
");
$vocabStmt->execute(['student_id' => $student['id']]);

$messagesStmt = $pdo->prepare("
SELECT m.role, m.content, m.transcription, m.created_at
FROM messages m
WHERE m.student_id = :student_id
ORDER BY m.created_at DESC
LIMIT 12
");
$messagesStmt->execute(['student_id' => $student['id']]);
$messages = array_reverse($messagesStmt->fetchAll());

json_response([
    'found' => true,
    'student' => $student,
    'weaknesses' => $errorsStmt->fetchAll(),
    'vocabulary_review' => $vocabStmt->fetchAll(),
    'recent_messages' => $messages,
]);
