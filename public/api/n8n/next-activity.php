<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_n8n_key();
$phone=normalize_phone($_GET['phone'] ?? '');
if(!$phone) json_response(['error'=>'phone é obrigatório'],422);
$pdo=db();
$q=$pdo->prepare("SELECT s.id,s.name,COALESCE(sp.overall_level,'A1') overall_level,sp.goal FROM students s LEFT JOIN student_profiles sp ON sp.student_id=s.id WHERE s.phone=:phone LIMIT 1");
$q->execute(['phone'=>$phone]); $student=$q->fetch();
if(!$student) json_response(['found'=>false,'phone'=>$phone]);
$q=$pdo->prepare("SELECT sa.id student_activity_id,a.id activity_id,a.title,a.description,a.activity_type,a.level,a.skill,a.instructions,a.content,a.xp_reward,a.estimated_minutes FROM student_activities sa JOIN activities a ON a.id=sa.activity_id WHERE sa.student_id=:id AND sa.status='pending' AND (sa.available_from IS NULL OR sa.available_from<=CURRENT_DATE) AND a.active=true ORDER BY sa.assigned_at LIMIT 1");
$q->execute(['id'=>$student['id']]); $activity=$q->fetch();
if(!$activity){
  $e=$pdo->prepare("SELECT topic,canonical_key,occurrences,mastery_score,original_text,corrected_text FROM student_errors WHERE student_id=:id AND status='learning' ORDER BY CASE WHEN next_review_at IS NULL OR next_review_at<=NOW() THEN 0 ELSE 1 END,occurrences DESC,mastery_score ASC LIMIT 1");
  $e->execute(['id'=>$student['id']]); $error=$e->fetch();
  $v=$pdo->prepare("SELECT v.word,v.translation,v.example,sv.mastery_score FROM student_vocabulary sv JOIN vocabulary v ON v.id=sv.vocabulary_id WHERE sv.student_id=:id AND sv.status IN ('learning','review') ORDER BY CASE WHEN sv.next_review_at IS NULL OR sv.next_review_at<=NOW() THEN 0 ELSE 1 END,sv.mastery_score ASC LIMIT 1");
  $v->execute(['id'=>$student['id']]); $vocab=$v->fetch();
  if($error){$type='grammar_review';$title='Revisão: '.($error['topic'] ?: 'Grammar');$instructions='Crie uma microatividade curta para revisar este erro recorrente.';$content=$error;$skill=$error['canonical_key'] ?: 'grammar';}
  elseif($vocab){$type='vocabulary_review';$title='Revisão de vocabulário';$instructions='Teste a palavra sem entregar a resposta antes do aluno tentar.';$content=$vocab;$skill='vocabulary';}
  else{$type='conversation_challenge';$title='Conversation challenge';$instructions='Crie uma pergunta curta e apropriada ao nível atual do aluno.';$content=['level'=>$student['overall_level']];$skill='speaking';}
  $i=$pdo->prepare("INSERT INTO activities(title,description,activity_type,level,skill,instructions,content,active,xp_reward,estimated_minutes,generated_by) VALUES(:title,'Atividade personalizada automaticamente.',:type,:level,:skill,:instructions,CAST(:content AS jsonb),true,10,10,'system') RETURNING id");
  $i->execute(['title'=>$title,'type'=>$type,'level'=>$student['overall_level'],'skill'=>$skill,'instructions'=>$instructions,'content'=>json_encode($content,JSON_UNESCAPED_UNICODE)]); $activityId=$i->fetchColumn();
  $a=$pdo->prepare("INSERT INTO student_activities(student_id,activity_id,status) VALUES(:sid,:aid,'pending')"); $a->execute(['sid'=>$student['id'],'aid'=>$activityId]);
  $q->execute(['id'=>$student['id']]); $activity=$q->fetch();
}
json_response(['found'=>true,'student'=>$student,'activity'=>$activity]);
