<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/progress.php';
require_n8n_key();
$data=json_input(); $phone=normalize_phone($data['phone'] ?? ''); $saId=trim($data['student_activity_id'] ?? ''); $score=max(0,min(100,(float)($data['score'] ?? 0))); $feedback=trim($data['feedback'] ?? '');
if(!$phone || !$saId) json_response(['error'=>'phone e student_activity_id são obrigatórios'],422);
$pdo=db();
try{$pdo->beginTransaction();
$q=$pdo->prepare("SELECT s.id FROM students s WHERE s.phone=:phone LIMIT 1");$q->execute(['phone'=>$phone]);$sid=$q->fetchColumn(); if(!$sid) throw new RuntimeException('Aluno não encontrado.');
$q=$pdo->prepare("SELECT sa.id,a.xp_reward,a.estimated_minutes FROM student_activities sa JOIN activities a ON a.id=sa.activity_id WHERE sa.id=:id AND sa.student_id=:sid LIMIT 1");$q->execute(['id'=>$saId,'sid'=>$sid]);$act=$q->fetch(); if(!$act) throw new RuntimeException('Atividade não encontrada.');
$xp=(int)$act['xp_reward'];
$pdo->prepare("UPDATE student_activities SET status='completed',completed_at=NOW(),score=:score,feedback=:feedback,xp_earned=:xp WHERE id=:id")->execute(['score'=>$score,'feedback'=>$feedback ?: null,'xp'=>$xp,'id'=>$saId]);
$pdo->prepare("UPDATE student_profiles SET xp=xp+:xp,last_study_at=NOW(),updated_at=NOW() WHERE student_id=:sid")->execute(['xp'=>$xp,'sid'=>$sid]);
$start=(new DateTimeImmutable('monday this week'))->format('Y-m-d');$end=(new DateTimeImmutable('sunday this week'))->format('Y-m-d');
$pdo->prepare("INSERT INTO weekly_goals(student_id,week_start,week_end,completed_minutes,completed_activities) VALUES(:sid,:start,:end,:minutes,1) ON CONFLICT(student_id,week_start) DO UPDATE SET completed_minutes=weekly_goals.completed_minutes+EXCLUDED.completed_minutes,completed_activities=weekly_goals.completed_activities+1,updated_at=NOW()")->execute(['sid'=>$sid,'start'=>$start,'end'=>$end,'minutes'=>(int)$act['estimated_minutes']]);
$pdo->commit(); progress_refresh_after_event((string)$sid); json_response(['success'=>true,'xp_earned'=>$xp],201);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['success'=>false,'error'=>$e->getMessage()],500);} 
