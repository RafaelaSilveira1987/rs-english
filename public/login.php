<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($user, $password)) {
        header('Location: /index.php');
        exit;
    }

    $error = 'Usuário ou senha inválidos.';
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
        <h1>RS English</h1>
        <p class="label">Painel administrativo</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="form-row">
            <label>Usuário</label>
            <input name="user" required>
        </div>

        <div class="form-row">
            <label>Senha</label>
            <input type="password" name="password" required>
        </div>

        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
