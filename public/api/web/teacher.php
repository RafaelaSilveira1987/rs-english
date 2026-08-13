<?php
declare(strict_types=1);

require_once __DIR__.'/../../../src/auth.php';
require_once __DIR__.'/../../../src/config.php';

header('Content-Type: application/json; charset=utf-8');

$user=require_student();

$raw=file_get_contents('php://input');
$data=json_decode($raw ?: '{}',true);

$message=trim((string)($data['message'] ?? ''));

if($message===''){
    http_response_code(422);
    echo json_encode(['error'=>'message é obrigatório']);
    exit;
}

$url=env('N8N_WEB_TEACHER_URL');

if(!$url){
    http_response_code(500);
    echo json_encode(['error'=>'N8N_WEB_TEACHER_URL não configurada']);
    exit;
}

$payload=[
    'student_id'=>$user['student_id'],
    'name'=>$user['name'],
    'phone'=>$user['phone'],
    'message'=>$message,
    'message_type'=>'text',
    'channel'=>'web'
];

$ch=curl_init($url);

curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>120,
    CURLOPT_HTTPHEADER=>[
        'Content-Type: application/json',
        'X-API-Key: '.(env('N8N_API_KEY') ?? '')
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE)
]);

$response=curl_exec($ch);
$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
$error=curl_error($ch);

curl_close($ch);

if($response===false || $error){
    http_response_code(502);
    echo json_encode(['error'=>'Falha ao acessar n8n','message'=>$error]);
    exit;
}

http_response_code($status ?: 200);
echo $response;
