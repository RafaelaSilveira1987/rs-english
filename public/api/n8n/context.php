<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();
$phone = normalize_phone($_GET['phone'] ?? '');
if (!$phone) json_response(['error' => 'phone é obrigatório'], 422);

$pdo = db();

$stmt = $pdo->prepare("
SELECT s.id,s.name,s.phone,s.email,
COALESCE(sp.overall_level,'A1') overall_level,
sp.estimated_level,sp.goal,
COALESCE(sp.correction_mode,'balanced') correction_mode,
COALESCE(sp.diagnostic_status,'pending') diagnostic_status,
COALESCE(sp.diagnostic_step,0) diagnostic_step,
sp.diagnostic_started_at,sp.diagnostic_completed_at,
COALESCE(sp.grammar_score,0) grammar_score,
COALESCE(sp.vocabulary_score,0) vocabulary_score,
COALESCE(sp.speaking_score,0) speaking_score,
COALESCE(sp.listening_score,0) listening_score,
COALESCE(sp.reading_score,0) reading_score,
COALESCE(sp.writing_score,0) writing_score,
COALESCE(sp.fluency_score,0) fluency_score,
COALESCE(sp.pronunciation_score,0) pronunciation_score
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id=s.id
WHERE s.phone=:phone LIMIT 1
");
$stmt->execute(['phone'=>$phone]);
$student=$stmt->fetch();

if (!$student) {
    json_response([
        'found'=>false,
        'phone'=>$phone,
        'diagnostic_status'=>'pending',
        'diagnostic_step'=>0
    ]);
}

$errors=$pdo->prepare("
SELECT category,topic,original_text,corrected_text,explanation,severity,occurrences
FROM student_errors
WHERE student_id=:id AND status='learning'
ORDER BY occurrences DESC, created_at DESC LIMIT 10
");
$errors->execute(['id'=>$student['id']]);

$vocab=$pdo->prepare("
SELECT v.word,v.translation,sv.status,sv.mastery_score,sv.next_review_at
FROM student_vocabulary sv
JOIN vocabulary v ON v.id=sv.vocabulary_id
WHERE sv.student_id=:id AND sv.status='learning'
ORDER BY sv.next_review_at NULLS LAST LIMIT 15
");
$vocab->execute(['id'=>$student['id']]);

$msg=$pdo->prepare("
SELECT role,content,transcription,created_at
FROM messages
WHERE student_id=:id
ORDER BY created_at DESC LIMIT 12
");
$msg->execute(['id'=>$student['id']]);
$recent=array_reverse($msg->fetchAll());

$plan=$pdo->prepare("
SELECT id,goal,target_level,plan_data,created_at
FROM study_plans
WHERE student_id=:id AND status='active'
ORDER BY created_at DESC LIMIT 1
");
$plan->execute(['id'=>$student['id']]);

json_response([
    'found'=>true,
    'student'=>$student,
    'diagnostic_status'=>$student['diagnostic_status'],
    'diagnostic_step'=>(int)$student['diagnostic_step'],
    'weaknesses'=>$errors->fetchAll(),
    'vocabulary_review'=>$vocab->fetchAll(),
    'recent_messages'=>$recent,
    'active_plan'=>$plan->fetch() ?: null
]);
