<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_n8n_key();

$phone = normalize_phone($_GET['phone'] ?? '');
if (!$phone) json_response(['error'=>'phone é obrigatório'],422);
$pdo = db();

$q=$pdo->prepare("SELECT s.id,s.name,s.phone,s.email,
COALESCE(sp.overall_level,'A1') overall_level,sp.estimated_level,sp.goal,
COALESCE(sp.correction_mode,'balanced') correction_mode,
COALESCE(sp.diagnostic_status,'pending') diagnostic_status,
COALESCE(sp.diagnostic_step,0) diagnostic_step,
COALESCE(sp.grammar_score,0) grammar_score,COALESCE(sp.vocabulary_score,0) vocabulary_score,
COALESCE(sp.speaking_score,0) speaking_score,COALESCE(sp.listening_score,0) listening_score,
COALESCE(sp.reading_score,0) reading_score,COALESCE(sp.writing_score,0) writing_score,
COALESCE(sp.fluency_score,0) fluency_score,COALESCE(sp.pronunciation_score,0) pronunciation_score,
COALESCE(sp.xp,0) xp,COALESCE(sp.streak_days,0) streak_days,sp.last_study_at
FROM students s LEFT JOIN student_profiles sp ON sp.student_id=s.id
WHERE s.phone=:phone LIMIT 1");
$q->execute(['phone'=>$phone]);
$student=$q->fetch();
if(!$student) json_response(['found'=>false,'phone'=>$phone,'diagnostic_status'=>'pending','diagnostic_step'=>0,'reviews_due'=>['vocabulary'=>[],'errors'=>[],'total'=>0]]);

$q=$pdo->prepare("SELECT id,category,topic,canonical_key,original_text,corrected_text,explanation,severity,occurrences,mastery_score,next_review_at,last_review_at
FROM student_errors WHERE student_id=:id AND status='learning'
ORDER BY occurrences DESC,mastery_score ASC,created_at DESC LIMIT 12");
$q->execute(['id'=>$student['id']]); $weaknesses=$q->fetchAll();

$q=$pdo->prepare("SELECT id,category,topic,canonical_key,original_text,corrected_text,explanation,occurrences,mastery_score,next_review_at
FROM student_errors WHERE student_id=:id AND status='learning' AND (next_review_at IS NULL OR next_review_at<=NOW())
ORDER BY occurrences DESC,mastery_score ASC LIMIT 8");
$q->execute(['id'=>$student['id']]); $errorDue=$q->fetchAll();

$q=$pdo->prepare("SELECT sv.id student_vocabulary_id,v.id vocabulary_id,v.word,v.translation,v.definition_en,v.example,v.level,v.category,
sv.status,sv.mastery_score,sv.repetitions,sv.correct_answers,sv.incorrect_answers,sv.next_review_at
FROM student_vocabulary sv JOIN vocabulary v ON v.id=sv.vocabulary_id
WHERE sv.student_id=:id AND sv.status IN ('learning','review') AND (sv.next_review_at IS NULL OR sv.next_review_at<=NOW())
ORDER BY sv.next_review_at NULLS FIRST,sv.mastery_score ASC LIMIT 10");
$q->execute(['id'=>$student['id']]); $vocabDue=$q->fetchAll();

$q=$pdo->prepare("SELECT role,content,transcription,message_type,created_at FROM messages
WHERE student_id=:id ORDER BY created_at DESC LIMIT 14");
$q->execute(['id'=>$student['id']]); $recent=array_reverse($q->fetchAll());

$q=$pdo->prepare("SELECT id,goal,target_level,plan_data,created_at,start_date,end_date FROM study_plans
WHERE student_id=:id AND status='active' ORDER BY created_at DESC LIMIT 1");
$q->execute(['id'=>$student['id']]); $plan=$q->fetch() ?: null;

$q=$pdo->prepare("SELECT COUNT(*) FILTER(WHERE status='mastered') mastered,
COUNT(*) FILTER(WHERE status IN ('learning','review')) learning,
COUNT(*) FILTER(WHERE status IN ('learning','review') AND (next_review_at IS NULL OR next_review_at<=NOW())) due
FROM student_vocabulary WHERE student_id=:id");
$q->execute(['id'=>$student['id']]); $stats=$q->fetch();

json_response(['found'=>true,'student'=>$student,'diagnostic_status'=>$student['diagnostic_status'],'diagnostic_step'=>(int)$student['diagnostic_step'],
'weaknesses'=>$weaknesses,'reviews_due'=>['vocabulary'=>$vocabDue,'errors'=>$errorDue,'total'=>count($vocabDue)+count($errorDue)],
'vocabulary_stats'=>$stats,'recent_messages'=>$recent,'active_plan'=>$plan]);
