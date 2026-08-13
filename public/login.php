<?php
declare(strict_types=1);

require_once __DIR__.'/../src/auth.php';

if(is_logged_in()){
    header('Location:'.post_login_redirect());
    exit;
}

$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(attempt_login(trim($_POST['login'] ?? ''),$_POST['password'] ?? '')){
        header('Location:'.post_login_redirect());
        exit;
    }

    $error='Usuário ou senha inválidos.';
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
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:22px">
        <div class="brand-mark">RS</div>
        <div>
            <h1>RS English</h1>
            <div class="label">AI English Coach</div>
        </div>
    </div>

    <?php if($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-row">
        <label>Usuário, e-mail ou telefone</label>
        <input name="login" required autofocus>
    </div>

    <div class="form-row">
        <label>Senha</label>
        <input type="password" name="password" required>
    </div>

    <button class="btn btn-primary" style="width:100%" type="submit">
        Entrar
    </button>
</form>
</div>
</body>
</html>
