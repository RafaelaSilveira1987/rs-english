<?php
declare(strict_types=1);

require_once __DIR__.'/../../../src/config.php';
require_once __DIR__.'/../../../src/audio.php';

header('Content-Type: application/json; charset=utf-8');

$key=$_SERVER['HTTP_X_API_KEY'] ?? '';

if(!hash_equals((string)env('N8N_API_KEY'),(string)$key)){
    http_response_code(401);
    echo json_encode(['error'=>'unauthorized']);
    exit;
}

if(empty($_FILES['audio']['tmp_name'])){
    http_response_code(422);
    echo json_encode(['error'=>'O campo multipart audio é obrigatório.']);
    exit;
}

try{
    $mime=(string)($_FILES['audio']['type'] ?? 'audio/webm');
    $result=transcribe_audio_file($_FILES['audio']['tmp_name'],$mime);

    echo json_encode([
        'ok'=>true,
        'text'=>$result['text']
    ],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'error'=>$e->getMessage()
    ],JSON_UNESCAPED_UNICODE);
}
