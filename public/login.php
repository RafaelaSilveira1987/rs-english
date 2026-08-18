<?php
declare(strict_types=1);
require_once __DIR__.'/../src/auth.php';
require_once __DIR__.'/../src/ui.php';

if(is_logged_in()){
    header('Location:'.post_login_redirect());
    exit;
}

$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(function_exists('verify_csrf')) verify_csrf();
    $login=trim($_POST['login'] ?? '');

    if(function_exists('login_is_rate_limited') && login_is_rate_limited($login)){
        $error='Muitas tentativas. Aguarde 15 minutos e tente novamente.';
    }elseif(attempt_login($login,$_POST['password'] ?? '')){
        header('Location:'.post_login_redirect());
        exit;
    }else{
        $error='Usuário ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07112b">
<title>Entrar — RS English</title>
<link rel="icon" href="/assets/images/rs-english-mark.webp" type="image/webp">
<link rel="stylesheet" href="/assets/css/app.css?v=10.6">
</head>
<body>
<div class="login-page">
    <section class="login-visual">
        <div class="login-brand">
            <img src="/assets/images/rs-english-horizontal-dark.webp" alt="RS English">
            <h2>Aprendizado de inglês com acompanhamento inteligente.</h2>
            <p>Diagnóstico adaptativo, prática de conversação, correções personalizadas e evolução acompanhada em um único ambiente.</p>
            <div class="login-feature-list">
                <div class="login-feature">Conversação com a Emma</div>
                <div class="login-feature">Progresso por competência</div>
                <div class="login-feature">Plano de estudo individual</div>
            </div>
        </div>
    </section>

    <section class="login-form-side">
        <form class="login-box" method="post">
            <?php if(function_exists('csrf_field')): ?><?= csrf_field() ?><?php endif; ?>
            <img class="login-logo-mobile" src="/assets/images/rs-english-horizontal-light.webp" alt="RS English">
            <div class="eyebrow">Acesso seguro</div>
            <h1>Bem-vindo</h1>
            <span class="muted">Entre com seus dados para acessar o portal.</span>

            <?php if($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

            <div class="form-row">
                <label>Usuário, e-mail ou telefone</label>
                <input name="login" required autofocus autocomplete="username" placeholder="Digite seu acesso">
            </div>

            <div class="form-row">
                <label>Senha</label>
                <input type="password" name="password" required autocomplete="current-password" placeholder="Digite sua senha">
            </div>

            <button class="btn btn-primary" type="submit">Entrar no portal</button>

            <div style="text-align:center;margin-top:17px">
                <a href="/forgot-password.php" class="muted">Esqueci minha senha</a>
            </div>
        </form>
    </section>
</div>
</body>
</html>
