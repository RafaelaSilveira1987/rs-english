<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_n8n_key();
$data=json_input(); $phone=normalize_phone($data['phone'] ?? ''); $summary=trim($data['teacher_summary'] ?? ''); $reportData=is_array($data['report_data'] ?? null)?$data['report_data']:[];
if(!$phone || !$reportData) json_response(['error'=>'phone e report_data são obrigatórios'],422);
$pdo=db(); $q=$pdo->prepare("SELECT id FROM students WHERE phone=:phone LIMIT 1"); $q->execute(['phone'=>$phone]); $studentId=$q->fetchColumn();
if(!$studentId) json_response(['error'=>'Aluno não encontrado'],404);
$start=$reportData['week']['start'] ?? null; $end=$reportData['week']['end'] ?? null;
$q=$pdo->prepare("INSERT INTO weekly_reports(student_id,week_start,week_end,report_data,teacher_summary,status) VALUES(:id,:start,:end,CAST(:data AS jsonb),:summary,'generated') ON CONFLICT(student_id,week_start) DO UPDATE SET report_data=EXCLUDED.report_data,teacher_summary=EXCLUDED.teacher_summary,status='generated',created_at=NOW() RETURNING id");
$q->execute(['id'=>$studentId,'start'=>$start,'end'=>$end,'data'=>json_encode($reportData,JSON_UNESCAPED_UNICODE),'summary'=>$summary]);
json_response(['success'=>true,'report_id'=>$q->fetchColumn()],201);
