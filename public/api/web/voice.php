<?php
declare(strict_types=1);

require_once __DIR__.'/../../../src/auth.php';
require_once __DIR__.'/../../../src/audio.php';
require_once __DIR__.'/../../../src/progress.php';
require_once __DIR__.'/../../../src/learning.php';

header('Content-Type: application/json; charset=utf-8');

$user=require_student();
$pdo=db();

if(empty($_FILES['audio']['tmp_name'])){
    http_response_code(422);
    echo json_encode(['error'=>'Nenhum áudio recebido.']);
    exit;
}

$maxBytes=12*1024*1024;

if((int)($_FILES['audio']['size'] ?? 0)>$maxBytes){
    http_response_code(413);
    echo json_encode(['error'=>'O áudio excede 12 MB.']);
    exit;
}

try{
    $mime=(string)($_FILES['audio']['type'] ?? 'audio/webm');
    $duration=(float)($_POST['duration_seconds'] ?? 0);
    $mode=in_array(($_POST['mode'] ?? 'conversation'),['conversation','diagnostic'],true)?$_POST['mode']:'conversation';
    $topic=trim((string)($_POST['topic'] ?? ($mode==='diagnostic'?'initial_diagnostic':'daily_life')));
    $style=trim((string)($_POST['style'] ?? 'guided'));
    $correctionMode=trim((string)($_POST['correction_mode'] ?? 'balanced'));
    $maxTurns=max(4,min(30,(int)($_POST['max_turns'] ?? 10)));

    $allowed=[
        'audio/webm','audio/ogg','audio/mpeg','audio/mp4',
        'audio/x-m4a','audio/wav','audio/x-wav'
    ];

    if(!in_array($mime,$allowed,true)){
        throw new RuntimeException('Formato de áudio não suportado: '.$mime);
    }

    $prefsStmt=$pdo->prepare("
        SELECT
            response_mode,voice_name,voice_speed,
            autoplay_audio,show_transcription
        FROM student_preferences
        WHERE student_id=:id
        LIMIT 1
    ");

    $prefsStmt->execute(['id'=>$user['student_id']]);
    $prefs=$prefsStmt->fetch() ?: [
        'response_mode'=>'automatic',
        'voice_name'=>'coral',
        'voice_speed'=>1.0,
        'autoplay_audio'=>true,
        'show_transcription'=>true
    ];

    $transcription=transcribe_audio_file(
        $_FILES['audio']['tmp_name'],
        $mime
    );

    $teacher=n8n_teacher_request([
        'student_id'=>$user['student_id'],
        'name'=>$user['name'],
        'phone'=>$user['phone'],
        'message'=>$transcription['text'],
        'message_type'=>'audio',
        'channel'=>'web_voice',
        'mode'=>$mode,
        'topic'=>$topic,
        'conversation'=>[
            'style'=>$style,
            'max_turns'=>$maxTurns
        ],
        'correction_mode'=>$correctionMode,
        'audio_duration_seconds'=>$duration
    ]);

    $teacherText=trim((string)($teacher['teacher_message'] ?? ''));

    if($teacherText===''){
        throw new RuntimeException('A Emma não retornou uma resposta.');
    }

    $speech=synthesize_speech(
        $teacherText,
        (string)$prefs['voice_name'],
        (float)$prefs['voice_speed'],
        'mp3'
    );

    $saved=save_voice_bytes($speech['bytes'],'mp3');

    $stmt=$pdo->prepare("
        INSERT INTO voice_conversations(
            student_id,channel,
            student_audio_mime,student_audio_duration_seconds,
            student_transcription,
            teacher_text,teacher_audio_path,teacher_voice,
            teacher_audio_format,status
        )
        VALUES(
            :student_id,'web_voice',
            :mime,:duration,:transcription,
            :teacher_text,:teacher_audio_path,:teacher_voice,
            'mp3','completed'
        )
        RETURNING id
    ");

    $stmt->execute([
        'student_id'=>$user['student_id'],
        'mime'=>$mime,
        'duration'=>$duration ?: null,
        'transcription'=>$transcription['text'],
        'teacher_text'=>$teacherText,
        'teacher_audio_path'=>$saved['relative'],
        'teacher_voice'=>$speech['voice']
    ]);

    $conversationId=(string)$stmt->fetchColumn();

    learning_record_event(
        $pdo,
        (string)$user['student_id'],
        learning_event_key('voice',[$conversationId]),
        'voice_practice',
        'web_voice',
        isset($teacher['session_id'])?(string)$teacher['session_id']:null,
        $conversationId,
        0,
        null,
        0,
        [
            'message_type'=>'audio',
            'mode'=>$mode,
            'topic'=>$topic,
            'transcription_length'=>mb_strlen((string)$transcription['text']),
            'media_duration_seconds'=>max(0,(int)round($duration)),
            'duration_recorded_by'=>'save-interaction'
        ]
    );

    progress_refresh_after_event((string)$user['student_id']);

    echo json_encode([
        'ok'=>true,
        'conversation_id'=>$conversationId,
        'transcription'=>$transcription['text'],
        'teacher_message'=>$teacherText,
        'teacher_audio_url'=>$saved['url'],
        'autoplay_audio'=>(bool)$prefs['autoplay_audio'],
        'show_transcription'=>(bool)$prefs['show_transcription'],
        'evaluation'=>$teacher['evaluation'] ?? null,
        'diagnostic'=>$teacher['diagnostic'] ?? null
    ],JSON_UNESCAPED_UNICODE);

}catch(Throwable $e){
    try{
        $pdo->prepare("
            INSERT INTO voice_conversations(
                student_id,channel,status,error_message
            )
            VALUES(:student_id,'web_voice','error',:error)
        ")->execute([
            'student_id'=>$user['student_id'],
            'error'=>substr($e->getMessage(),0,2000)
        ]);
    }catch(Throwable $ignored){}

    http_response_code(500);

    echo json_encode([
        'ok'=>false,
        'error'=>$e->getMessage()
    ],JSON_UNESCAPED_UNICODE);
}
