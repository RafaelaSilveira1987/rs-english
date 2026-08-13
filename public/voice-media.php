<?php
declare(strict_types=1);

require_once __DIR__.'/../src/auth.php';

require_login();

$relative=trim((string)($_GET['file'] ?? ''));

if(
    $relative==='' ||
    str_contains($relative,'..') ||
    str_starts_with($relative,'/')
){
    http_response_code(400);
    exit('Arquivo inválido.');
}

$base=realpath(__DIR__.'/../storage/voice');

if(!$base){
    http_response_code(404);
    exit('Diretório não encontrado.');
}

$file=realpath($base.'/'.$relative);

if(!$file || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file)){
    http_response_code(404);
    exit('Áudio não encontrado.');
}

$extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));
$contentTypes=[
    'mp3'=>'audio/mpeg',
    'wav'=>'audio/wav',
    'ogg'=>'audio/ogg',
    'opus'=>'audio/ogg',
    'webm'=>'audio/webm',
    'm4a'=>'audio/mp4'
];

header('Content-Type: '.($contentTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: '.filesize($file));
header('Cache-Control: private, max-age=86400');
readfile($file);
