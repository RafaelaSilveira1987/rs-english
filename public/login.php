<?php
declare(strict_types=1);
require_once __DIR__.'/../src/auth.php';

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
<title>Login - RS English</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="login-page">
<form class="login-box" method="post">
    <?php if(function_exists('csrf_field')): ?><?= csrf_field() ?><?php endif; ?>

    <div style="display:flex;gap:12px;align-items:center;margin-bottom:22px">
        <div class="brand-mark">RS</div>
        <div><h1>RS English</h1><div class="label">AI English Coach</div></div>
    </div>

    <?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="form-row">
        <label>Usuário, e-mail ou telefone</label>
        <input name="login" required autofocus autocomplete="username">
    </div>

    <div class="form-row">
        <label>Senha</label>
        <input type="password" name="password" required autocomplete="current-password">
    </div>

    <button class="btn btn-primary" style="width:100%" type="submit">Entrar</button>

    <div style="text-align:center;margin-top:16px">
        <a href="/forgot-password.php" class="label">Esqueci minha senha</a>
    </div>
</form>
</div>
</body>
</html>
