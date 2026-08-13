<?php
declare(strict_types=1);

require_once __DIR__.'/../../../src/config.php';
require_once __DIR__.'/../../../src/audio.php';

$key=$_SERVER['HTTP_X_API_KEY'] ?? '';

if(!hash_equals((string)env('N8N_API_KEY'),(string)$key)){
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error'=>'unauthorized']);
    exit;
}

$data=json_decode(file_get_contents('php://input') ?: '{}',true);

try{
    $speech=synthesize_speech(
        (string)($data['text'] ?? ''),
        (string)($data['voice'] ?? 'coral'),
        (float)($data['speed'] ?? 1.0),
        (string)($data['format'] ?? 'mp3')
    );

    if(!empty($data['return_base64'])){
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'ok'=>true,
            'mime'=>$speech['content_type'],
            'format'=>$speech['format'],
            'base64'=>base64_encode($speech['bytes'])
        ]);
        exit;
    }

    header('Content-Type: '.$speech['content_type']);
    header('Content-Disposition: attachment; filename="emma.'.$speech['format'].'"');
    echo $speech['bytes'];
}catch(Throwable $e){
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok'=>false,
        'error'=>$e->getMessage()
    ],JSON_UNESCAPED_UNICODE);
}
