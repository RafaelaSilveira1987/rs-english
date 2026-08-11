<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();
$data=json_input();

$phone=normalize_phone($data['phone'] ?? '');
$name=trim($data['student_name'] ?? 'Aluno');
$studentMessage=trim($data['student_message'] ?? '');
$teacherMessage=trim($data['teacher_message'] ?? '');
$diagnostic=is_array($data['diagnostic'] ?? null) ? $data['diagnostic'] : [];

if (!$phone) json_response(['error'=>'phone é obrigatório'],422);

$nextStep=max(0,(int)($diagnostic['next_step'] ?? 1));
$complete=!empty($diagnostic['complete']);
$level=strtoupper(trim((string)($diagnostic['estimated_level'] ?? 'A1')));
if (!in_array($level,['A1','A2','B1','B2','C1','C2'],true)) $level='A1';

$scores=is_array($diagnostic['scores'] ?? null) ? $diagnostic['scores'] : [];
$strengths=is_array($diagnostic['strengths'] ?? null) ? $diagnostic['strengths'] : [];
$weaknesses=is_array($diagnostic['weaknesses'] ?? null) ? $diagnostic['weaknesses'] : [];
$recommendations=is_array($diagnostic['recommendations'] ?? null) ? $diagnostic['recommendations'] : [];
$studyPlan=is_array($diagnostic['study_plan'] ?? null) ? $diagnostic['study_plan'] : [];

$pdo=db();

try {
    $pdo->beginTransaction();

    $q=$pdo->prepare("SELECT id FROM students WHERE phone=:phone LIMIT 1");
    $q->execute(['phone'=>$phone]);
    $studentId=$q->fetchColumn();

    if (!$studentId) {
        $q=$pdo->prepare("INSERT INTO students(name,phone) VALUES(:name,:phone) RETURNING id");
        $q->execute(['name'=>$name ?: 'Aluno','phone'=>$phone]);
        $studentId=$q->fetchColumn();

        $q=$pdo->prepare("
        INSERT INTO student_profiles(
          student_id,overall_level,estimated_level,goal,correction_mode,
          diagnostic_status,diagnostic_step,diagnostic_started_at
        ) VALUES(
          :id,'A1','A1','Aprender inglês','balanced','in_progress',0,NOW()
        )");
        $q->execute(['id'=>$studentId]);
    }

    $q=$pdo->prepare("
    SELECT id FROM sessions
    WHERE student_id=:id AND status='active' AND mode='assessment'
      AND created_at >= NOW()-INTERVAL '24 hours'
    ORDER BY created_at DESC LIMIT 1");
    $q->execute(['id'=>$studentId]);
    $sessionId=$q->fetchColumn();

    if (!$sessionId) {
        $q=$pdo->prepare("
        INSERT INTO sessions(student_id,channel,mode,topic,level,status)
        VALUES(:id,'whatsapp','assessment','initial_diagnostic',:level,'active')
        RETURNING id");
        $q->execute(['id'=>$studentId,'level'=>$level]);
        $sessionId=$q->fetchColumn();
    }

    if ($studentMessage!=='') {
        $q=$pdo->prepare("
        INSERT INTO messages(session_id,student_id,role,message_type,content)
        VALUES(:sid,:id,'student','text',:content)");
        $q->execute(['sid'=>$sessionId,'id'=>$studentId,'content'=>$studentMessage]);
    }

    if ($teacherMessage!=='') {
        $q=$pdo->prepare("
        INSERT INTO messages(session_id,student_id,role,message_type,content)
        VALUES(:sid,:id,'teacher','text',:content)");
        $q->execute(['sid'=>$sessionId,'id'=>$studentId,'content'=>$teacherMessage]);
    }

    if (!$complete) {
        $q=$pdo->prepare("
        UPDATE student_profiles SET
          diagnostic_status='in_progress',
          diagnostic_step=:step,
          estimated_level=:level,
          diagnostic_started_at=COALESCE(diagnostic_started_at,NOW()),
          last_study_at=NOW(),
          updated_at=NOW()
        WHERE student_id=:id");
        $q->execute(['step'=>$nextStep,'level'=>$level,'id'=>$studentId]);

        $pdo->commit();
        json_response([
            'success'=>true,'complete'=>false,
            'student_id'=>$studentId,'session_id'=>$sessionId,
            'next_step'=>$nextStep
        ],201);
    }

    $g=(float)($scores['grammar'] ?? 0);
    $v=(float)($scores['vocabulary'] ?? 0);
    $sp=(float)($scores['speaking'] ?? 0);
    $li=(float)($scores['listening'] ?? 0);
    $r=(float)($scores['reading'] ?? 0);
    $w=(float)($scores['writing'] ?? 0);
    $f=(float)($scores['fluency'] ?? 0);

    $q=$pdo->prepare("
    UPDATE student_profiles SET
      overall_level=:level,estimated_level=:level,
      diagnostic_status='completed',
      diagnostic_step=:step,
      diagnostic_completed_at=NOW(),
      grammar_score=:g,vocabulary_score=:v,speaking_score=:sp,
      listening_score=:li,reading_score=:r,writing_score=:w,
      fluency_score=:f,last_study_at=NOW(),updated_at=NOW()
    WHERE student_id=:id");
    $q->execute([
        'level'=>$level,'step'=>$nextStep,'g'=>$g,'v'=>$v,'sp'=>$sp,
        'li'=>$li,'r'=>$r,'w'=>$w,'f'=>$f,'id'=>$studentId
    ]);

    $q=$pdo->prepare("SELECT id FROM assessments WHERE assessment_type='initial_diagnostic' LIMIT 1");
    $q->execute();
    $assessmentId=$q->fetchColumn();

    if (!$assessmentId) {
        $q=$pdo->prepare("
        INSERT INTO assessments(title,assessment_type,level,active)
        VALUES('Diagnóstico Inicial','initial_diagnostic',:level,true)
        RETURNING id");
        $q->execute(['level'=>$level]);
        $assessmentId=$q->fetchColumn();
    }

    $total=round(($g+$v+$sp+$li+$r+$w+$f)/7,2);

    $q=$pdo->prepare("
    INSERT INTO assessment_results(
      assessment_id,student_id,overall_level,
      grammar_score,vocabulary_score,speaking_score,listening_score,
      reading_score,writing_score,fluency_score,total_score,
      strengths,weaknesses,recommendations,evaluator_feedback
    ) VALUES(
      :aid,:id,:level,:g,:v,:sp,:li,:r,:w,:f,:total,
      CAST(:strengths AS jsonb),CAST(:weaknesses AS jsonb),
      CAST(:recommendations AS jsonb),:feedback
    )");
    $q->execute([
        'aid'=>$assessmentId,'id'=>$studentId,'level'=>$level,
        'g'=>$g,'v'=>$v,'sp'=>$sp,'li'=>$li,'r'=>$r,'w'=>$w,'f'=>$f,
        'total'=>$total,
        'strengths'=>json_encode($strengths,JSON_UNESCAPED_UNICODE),
        'weaknesses'=>json_encode($weaknesses,JSON_UNESCAPED_UNICODE),
        'recommendations'=>json_encode($recommendations,JSON_UNESCAPED_UNICODE),
        'feedback'=>(string)($diagnostic['feedback'] ?? '')
    ]);

    $pdo->prepare("UPDATE study_plans SET status='archived' WHERE student_id=:id AND status='active'")
        ->execute(['id'=>$studentId]);

    $map=['A1'=>'A2','A2'=>'B1','B1'=>'B2','B2'=>'C1','C1'=>'C2','C2'=>'C2'];
    $target=$map[$level] ?? 'A2';

    $q=$pdo->prepare("
    INSERT INTO study_plans(student_id,start_date,end_date,goal,target_level,status,plan_data)
    VALUES(:id,CURRENT_DATE,CURRENT_DATE+28,:goal,:target,'active',CAST(:plan AS jsonb))");
    $q->execute([
        'id'=>$studentId,
        'goal'=>(string)($studyPlan['goal'] ?? 'Melhorar conversação em inglês'),
        'target'=>$target,
        'plan'=>json_encode($studyPlan,JSON_UNESCAPED_UNICODE)
    ]);

    $q=$pdo->prepare("
    UPDATE sessions SET
      status='completed',ended_at=NOW(),level=:level,
      grammar_score=:g,vocabulary_score=:v,fluency_score=:f,
      comprehension_score=:c
    WHERE id=:sid");
    $q->execute([
        'level'=>$level,'g'=>$g,'v'=>$v,'f'=>$f,
        'c'=>round(($li+$r)/2,2),'sid'=>$sessionId
    ]);

    $pdo->commit();

    json_response([
        'success'=>true,'complete'=>true,
        'student_id'=>$studentId,'session_id'=>$sessionId,
        'official_level'=>$level,'target_level'=>$target
    ],201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['success'=>false,'error'=>$e->getMessage()],500);
}
