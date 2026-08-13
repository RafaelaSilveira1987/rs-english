<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';

function voice_storage_dir(): string
{
    $dir=dirname(__DIR__).'/storage/voice';

    if(!is_dir($dir)){
        mkdir($dir,0775,true);
    }

    return $dir;
}

function voice_public_path(string $filename): string
{
    return '/voice-media.php?file='.rawurlencode($filename);
}

function openai_api_key(): string
{
    $key=(string)env('OPENAI_API_KEY');

    if($key===''){
        throw new RuntimeException('OPENAI_API_KEY não configurada.');
    }

    return $key;
}

function transcribe_audio_file(string $path,string $mime='audio/webm'): array
{
    if(!is_file($path)){
        throw new RuntimeException('Arquivo de áudio não encontrado.');
    }

    $model=(string)env('OPENAI_TRANSCRIPTION_MODEL','gpt-4o-mini-transcribe');

    $ch=curl_init('https://api.openai.com/v1/audio/transcriptions');

    $post=[
        'model'=>$model,
        'file'=>new CURLFile($path,$mime,basename($path)),
        'response_format'=>'json'
    ];

    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.openai_api_key()
        ],
        CURLOPT_POSTFIELDS=>$post
    ]);

    $body=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);

    if($body===false || $error){
        throw new RuntimeException('Falha na transcrição: '.$error);
    }

    $data=json_decode($body,true);

    if($status<200 || $status>=300){
        throw new RuntimeException(
            'OpenAI transcription HTTP '.$status.': '.
            ($data['error']['message'] ?? $body)
        );
    }

    $text=trim((string)($data['text'] ?? ''));

    if($text===''){
        throw new RuntimeException('A transcrição retornou vazia.');
    }

    return [
        'text'=>$text,
        'raw'=>$data
    ];
}

function synthesize_speech(
    string $text,
    string $voice='coral',
    float $speed=1.0,
    string $format='mp3'
): array {
    $text=trim($text);

    if($text===''){
        throw new RuntimeException('Texto vazio para geração de voz.');
    }

    $allowedVoices=['alloy','ash','ballad','coral','echo','fable','nova','onyx','sage','shimmer','verse'];

    if(!in_array($voice,$allowedVoices,true)){
        $voice='coral';
    }

    $speed=max(0.75,min(1.35,$speed));
    $model=(string)env('OPENAI_TTS_MODEL','gpt-4o-mini-tts');

    $payload=[
        'model'=>$model,
        'voice'=>$voice,
        'input'=>$text,
        'response_format'=>$format,
        'speed'=>$speed,
        'instructions'=>'Speak clearly and warmly as an English tutor. Use natural pacing and accurate English pronunciation.'
    ];

    $ch=curl_init('https://api.openai.com/v1/audio/speech');

    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.openai_api_key(),
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE)
    ]);

    $body=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $contentType=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    $error=curl_error($ch);
    curl_close($ch);

    if($body===false || $error){
        throw new RuntimeException('Falha na geração de voz: '.$error);
    }

    if($status<200 || $status>=300){
        $decoded=json_decode($body,true);

        throw new RuntimeException(
            'OpenAI speech HTTP '.$status.': '.
            ($decoded['error']['message'] ?? $body)
        );
    }

    return [
        'bytes'=>$body,
        'content_type'=>$contentType ?: 'audio/mpeg',
        'format'=>$format,
        'voice'=>$voice,
        'speed'=>$speed
    ];
}

function save_voice_bytes(string $bytes,string $extension='mp3'): array
{
    $safeExtension=preg_replace('/[^a-z0-9]/i','',$extension) ?: 'mp3';
    $filename=date('Y/m/').bin2hex(random_bytes(16)).'.'.$safeExtension;
    $full=voice_storage_dir().'/'.$filename;

    if(!is_dir(dirname($full))){
        mkdir(dirname($full),0775,true);
    }

    if(file_put_contents($full,$bytes)===false){
        throw new RuntimeException('Não foi possível salvar o áudio gerado.');
    }

    return [
        'path'=>$full,
        'relative'=>$filename,
        'url'=>voice_public_path($filename)
    ];
}

function n8n_teacher_request(array $payload): array
{
    $url=(string)env('N8N_WEB_TEACHER_URL');

    if($url===''){
        throw new RuntimeException('N8N_WEB_TEACHER_URL não configurada.');
    }

    $ch=curl_init($url);

    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'X-API-Key: '.(string)env('N8N_API_KEY')
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE)
    ]);

    $body=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);

    if($body===false || $error){
        throw new RuntimeException('Falha ao acessar o n8n: '.$error);
    }

    $data=json_decode($body,true);

    if($status<200 || $status>=300){
        throw new RuntimeException(
            'n8n HTTP '.$status.': '.($data['error'] ?? $data['message'] ?? $body)
        );
    }

    return is_array($data) ? $data : [];
}
