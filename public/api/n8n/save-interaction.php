<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_n8n_key();

$data=json_input();
$phone=normalize_phone($data['phone']??'');
$name=trim($data['student_name']??'Aluno');
$studentMessage=trim($data['student_message']??'');
$teacherMessage=trim($data['teacher_message']??'');
$messageType=trim($data['message_type']??'text');
$mode=trim($data['mode']??'conversation');
$topic=trim($data['topic']??'');
$evaluation=is_array($data['evaluation']??null)?$data['evaluation']:[];
if(!$phone||!$studentMessage) json_response(['error'=>'phone e student_message são obrigatórios'],422);

function clamp_score_v4($v){return max(0,min(100,(float)$v));}
function canonical_key_v4(array $e):string{
  $s=strtolower(trim((string)($e['topic']??$e['category']??'other')));
  $s=preg_replace('/[^a-z0-9_]+/i','_',$s); return trim($s,'_')?:'other';
}
function normalize_word_v4(string $w):string{return preg_replace('/\s+/',' ',trim(mb_strtolower($w)));}

$pdo=db();
try{
  $pdo->beginTransaction();
  $q=$pdo->prepare("SELECT id FROM students WHERE phone=:phone LIMIT 1"); $q->execute(['phone'=>$phone]); $studentId=$q->fetchColumn();
  if(!$studentId){
    $q=$pdo->prepare("INSERT INTO students(name,phone) VALUES(:name,:phone) RETURNING id"); $q->execute(['name'=>$name?:'Aluno','phone'=>$phone]); $studentId=$q->fetchColumn();
    $pdo->prepare("INSERT INTO student_profiles(student_id,overall_level,goal,correction_mode,diagnostic_status,diagnostic_step)
    VALUES(:id,'A1','Aprender inglês','balanced','pending',0)")->execute(['id'=>$studentId]);
  }

  $q=$pdo->prepare("SELECT id FROM sessions WHERE student_id=:id AND status='active' AND mode=:mode AND created_at>=NOW()-INTERVAL '4 hours' ORDER BY created_at DESC LIMIT 1");
  $q->execute(['id'=>$studentId,'mode'=>$mode]); $sessionId=$q->fetchColumn();
  if(!$sessionId){
    $q=$pdo->prepare("INSERT INTO sessions(student_id,channel,mode,topic,status) VALUES(:id,'whatsapp',:mode,:topic,'active') RETURNING id");
    $q->execute(['id'=>$studentId,'mode'=>$mode,'topic'=>$topic?:null]); $sessionId=$q->fetchColumn();
  }

  $q=$pdo->prepare("INSERT INTO messages(session_id,student_id,role,message_type,content,transcription)
  VALUES(:sid,:id,'student',:type,:content,:transcription) RETURNING id");
  $q->execute(['sid'=>$sessionId,'id'=>$studentId,'type'=>$messageType,'content'=>$studentMessage,'transcription'=>$messageType==='audio'?$studentMessage:null]);
  $messageId=$q->fetchColumn();
  if($teacherMessage!=='') $pdo->prepare("INSERT INTO messages(session_id,student_id,role,message_type,content) VALUES(:sid,:id,'teacher','text',:content)")
    ->execute(['sid'=>$sessionId,'id'=>$studentId,'content'=>$teacherMessage]);

  foreach(($evaluation['errors']??[]) as $e){
    if(!is_array($e)) continue; $key=canonical_key_v4($e);
    $q=$pdo->prepare("SELECT id FROM student_errors WHERE student_id=:id AND canonical_key=:k AND status='learning' LIMIT 1");
    $q->execute(['id'=>$studentId,'k'=>$key]); $existing=$q->fetchColumn();
    if($existing){
      $pdo->prepare("UPDATE student_errors SET category=COALESCE(:category,category),topic=COALESCE(:topic,topic),original_text=COALESCE(:original,original_text),
      corrected_text=COALESCE(:corrected,corrected_text),explanation=COALESCE(:explanation,explanation),severity=COALESCE(:severity,severity),
      occurrences=occurrences+1,mastery_score=GREATEST(0,mastery_score-5),next_review_at=NOW()+INTERVAL '1 day' WHERE id=:eid")
      ->execute(['category'=>$e['category']??null,'topic'=>$e['topic']??null,'original'=>$e['original']??null,'corrected'=>$e['corrected']??null,
      'explanation'=>$e['explanation']??null,'severity'=>$e['severity']??'medium','eid'=>$existing]);
    } else {
      $pdo->prepare("INSERT INTO student_errors(student_id,session_id,message_id,category,topic,canonical_key,original_text,corrected_text,explanation,severity,occurrences,mastery_score,status,next_review_at)
      VALUES(:id,:sid,:mid,:category,:topic,:k,:original,:corrected,:explanation,:severity,1,0,'learning',NOW()+INTERVAL '1 day')")
      ->execute(['id'=>$studentId,'sid'=>$sessionId,'mid'=>$messageId,'category'=>$e['category']??null,'topic'=>$e['topic']??null,'k'=>$key,
      'original'=>$e['original']??null,'corrected'=>$e['corrected']??null,'explanation'=>$e['explanation']??null,'severity'=>$e['severity']??'medium']);
    }
  }

  foreach(($evaluation['vocabulary']??[]) as $v){
    if(!is_array($v)) continue; $word=trim((string)($v['word']??'')); if($word==='') continue; $norm=normalize_word_v4($word);
    $q=$pdo->prepare("SELECT id FROM vocabulary WHERE normalized_word=:n LIMIT 1"); $q->execute(['n'=>$norm]); $vid=$q->fetchColumn();
    if(!$vid){
      $q=$pdo->prepare("INSERT INTO vocabulary(word,normalized_word,translation,definition_en,example,level,category)
      VALUES(:word,:n,:translation,:definition,:example,:level,:category) RETURNING id");
      $q->execute(['word'=>$word,'n'=>$norm,'translation'=>$v['translation']??null,'definition'=>$v['definition_en']??null,'example'=>$v['example']??null,'level'=>$v['level']??null,'category'=>$v['category']??null]);
      $vid=$q->fetchColumn();
    }
    $pdo->prepare("INSERT INTO student_vocabulary(student_id,vocabulary_id,status,mastery_score,repetitions,correct_answers,incorrect_answers,first_seen_at,next_review_at,interval_days,ease_factor)
    VALUES(:id,:vid,'learning',0,0,0,0,NOW(),NOW()+INTERVAL '1 day',1,2.50)
    ON CONFLICT(student_id,vocabulary_id) DO UPDATE SET next_review_at=COALESCE(student_vocabulary.next_review_at,NOW()+INTERVAL '1 day')")
    ->execute(['id'=>$studentId,'vid'=>$vid]);
  }

  foreach(($evaluation['review_results']??[]) as $r){
    if(!is_array($r)||empty($r['word'])) continue; $norm=normalize_word_v4((string)$r['word']); $correct=!empty($r['correct']);
    $q=$pdo->prepare("SELECT sv.id,sv.mastery_score,sv.repetitions,sv.interval_days,sv.ease_factor FROM student_vocabulary sv JOIN vocabulary v ON v.id=sv.vocabulary_id
    WHERE sv.student_id=:id AND v.normalized_word=:n LIMIT 1"); $q->execute(['id'=>$studentId,'n'=>$norm]); $sv=$q->fetch(); if(!$sv) continue;
    $mastery=(float)$sv['mastery_score']; $reps=(int)$sv['repetitions']+1; $interval=max(1,(int)$sv['interval_days']); $ease=(float)$sv['ease_factor'];
    if($correct){$mastery=min(100,$mastery+15);$interval=$reps<=1?2:min(30,max(3,(int)round($interval*$ease)));$ease=min(3.0,$ease+.05);}
    else{$mastery=max(0,$mastery-15);$interval=1;$ease=max(1.3,$ease-.20);}
    $status=$mastery>=85?'mastered':'learning';
    $pdo->prepare("UPDATE student_vocabulary SET status=:status,mastery_score=:mastery,repetitions=:reps,correct_answers=correct_answers+:ci,incorrect_answers=incorrect_answers+:ii,
    last_review_at=NOW(),next_review_at=NOW()+(:days||' days')::interval,interval_days=:days,ease_factor=:ease WHERE id=:id")
    ->execute(['status'=>$status,'mastery'=>$mastery,'reps'=>$reps,'ci'=>$correct?1:0,'ii'=>$correct?0:1,'days'=>$interval,'ease'=>$ease,'id'=>$sv['id']]);
  }

  foreach(($evaluation['error_review_results']??[]) as $r){
    if(!is_array($r)||empty($r['topic'])) continue; $k=strtolower(preg_replace('/[^a-z0-9_]+/i','_',trim((string)$r['topic']))); $correct=!empty($r['correct']);
    $q=$pdo->prepare("SELECT id,mastery_score FROM student_errors WHERE student_id=:id AND canonical_key=:k AND status='learning' LIMIT 1"); $q->execute(['id'=>$studentId,'k'=>$k]); $er=$q->fetch(); if(!$er) continue;
    $mastery=$correct?min(100,(float)$er['mastery_score']+20):max(0,(float)$er['mastery_score']-10); $status=$mastery>=85?'mastered':'learning'; $days=$correct?($mastery>=60?7:3):1;
    $pdo->prepare("UPDATE student_errors SET mastery_score=:m,status=:status,last_review_at=NOW(),next_review_at=NOW()+(:days||' days')::interval WHERE id=:id")
      ->execute(['m'=>$mastery,'status'=>$status,'days'=>$days,'id'=>$er['id']]);
  }

  foreach(($evaluation['skills']??[]) as $s){
    if(!is_array($s)||empty($s['code'])) continue; $q=$pdo->prepare("SELECT id FROM skills WHERE code=:c LIMIT 1"); $q->execute(['c'=>$s['code']]); $sid=$q->fetchColumn(); if(!$sid) continue;
    $score=clamp_score_v4($s['score']??0); $success=!empty($s['success'])?1:0;
    $pdo->prepare("INSERT INTO student_skills(student_id,skill_id,score,attempts,successes,last_practiced_at,updated_at)
    VALUES(:id,:sid,:score,1,:success,NOW(),NOW()) ON CONFLICT(student_id,skill_id) DO UPDATE SET score=ROUND(((student_skills.score*3)+EXCLUDED.score)/4,2),attempts=student_skills.attempts+1,
    successes=student_skills.successes+EXCLUDED.successes,last_practiced_at=NOW(),updated_at=NOW()")
    ->execute(['id'=>$studentId,'sid'=>$sid,'score'=>$score,'success'=>$success]);
  }

  $g=array_key_exists('grammar_score',$evaluation)?clamp_score_v4($evaluation['grammar_score']):null;
  $v=array_key_exists('vocabulary_score',$evaluation)?clamp_score_v4($evaluation['vocabulary_score']):null;
  $f=array_key_exists('fluency_score',$evaluation)?clamp_score_v4($evaluation['fluency_score']):null;
  $c=array_key_exists('comprehension_score',$evaluation)?clamp_score_v4($evaluation['comprehension_score']):null;
  $pdo->prepare("UPDATE sessions SET grammar_score=COALESCE(:g,grammar_score),vocabulary_score=COALESCE(:v,vocabulary_score),fluency_score=COALESCE(:f,fluency_score),comprehension_score=COALESCE(:c,comprehension_score) WHERE id=:id")
    ->execute(['g'=>$g,'v'=>$v,'f'=>$f,'c'=>$c,'id'=>$sessionId]);
  $pdo->prepare("UPDATE student_profiles SET grammar_score=COALESCE(:g,grammar_score),vocabulary_score=COALESCE(:v,vocabulary_score),fluency_score=COALESCE(:f,fluency_score),last_study_at=NOW(),xp=xp+:xp,updated_at=NOW() WHERE student_id=:id")
    ->execute(['g'=>$g,'v'=>$v,'f'=>$f,'xp'=>$mode==='review'?8:5,'id'=>$studentId]);

  $pdo->commit(); json_response(['success'=>true,'student_id'=>$studentId,'session_id'=>$sessionId,'mode'=>$mode],201);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['success'=>false,'error'=>$e->getMessage()],500);}
