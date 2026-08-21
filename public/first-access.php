<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/ui.php';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Primeiro acesso — RS English</title>
<link rel="icon" href="/assets/images/rs-english-mark-transparent.png" type="image/png">
<link rel="stylesheet" href="/assets/css/app.css?v=16.0">
</head>
<body class="login-body">
<div class="login-page">
    <div class="login-box">
        <img class="login-logo-mobile" src="/assets/images/rs-english-horizontal-transparent.png" alt="RS English">
        <h1>Primeiro acesso</h1>
        <p>Seu acesso é criado automaticamente a partir do cadastro usado pela Emma no WhatsApp. No primeiro acesso, você escolhe seu nome de usuário e cria sua senha.</p>
        <div class="list-card">
            <strong>Como receber o link</strong>
            <p>Envie a palavra <strong>ACESSO</strong> para a Emma no mesmo WhatsApp em que você estuda. Ela enviará um novo link seguro para escolher seu usuário e criar sua senha.</p>
        </div>
        <a class="btn btn-primary" style="width:100%;margin-top:16px" href="/login.php">Voltar ao login</a>
    </div>
</div>
</body>
</html>
