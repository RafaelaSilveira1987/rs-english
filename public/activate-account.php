<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/access.php';
require_once __DIR__ . '/../src/ui.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$record = access_validate_activation_token($token);
$error = null;
$activated = false;
$selectedUsername = (string)($record['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $record = access_validate_activation_token($token);
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $selectedUsername = trim((string)($_POST['username'] ?? ''));

    try {
        if (!$record) {
            throw new RuntimeException('Este link expirou ou já foi utilizado.');
        }
        if ($password !== $confirmation) {
            throw new RuntimeException('As senhas não são iguais.');
        }
        if (!password_is_strong($password)) {
            throw new RuntimeException('Use pelo menos 8 caracteres, com maiúscula, minúscula e número.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }

        $authVersion = access_activate_account(
            $record,
            $password,
            $email !== '' ? $email : null,
            $selectedUsername
        );
        session_regenerate_id(true);
        $_SESSION['user_id'] = $record['user_id'];
        $_SESSION['auth_version'] = $authVersion;
        unset($_SESSION['legacy_admin']);
        forget_current_user_cache();
        $activated = true;
    } catch (Throwable $e) {
        $error = str_contains($e->getMessage(), 'duplicate key')
            ? 'Este usuário ou e-mail já está sendo usado. Escolha outro.'
            : $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f8ff">
<title>Ativar acesso — RS English</title>
<link rel="icon" href="/assets/images/rs-english-mark-transparent.png" type="image/png">
<link rel="stylesheet" href="/assets/css/app.css?v=16.0">
</head>
<body class="login-body">
<div class="login-page login-page-redesign">
    <section class="login-visual" aria-label="Ativação do portal RS English">
        <div class="login-ambient login-ambient-one" aria-hidden="true"></div>
        <div class="login-ambient login-ambient-two" aria-hidden="true"></div>
        <div class="login-brand">
            <img class="login-brand-logo" src="/assets/images/rs-english-horizontal-transparent.png" alt="RS English">
            <div class="login-welcome-pill"><?= ui_icon('sparkles', 'icon-sm') ?><span>Seu portal já está preparado</span></div>
            <h1 class="login-hero-title">Escolha seu usuário, crie sua senha e acompanhe <span>toda a sua evolução.</span></h1>
            <p class="login-hero-copy">O mesmo aluno atendido pela Emma no WhatsApp será usado no portal, sem duplicar cadastro ou progresso.</p>
        </div>
    </section>
    <section class="login-form-side">
        <div class="login-box">
            <img class="login-logo-mobile" src="/assets/images/rs-english-horizontal-transparent.png" alt="RS English">
            <?php if ($activated): ?>
                <div class="login-secure-label"><?= ui_icon('shield', 'icon-sm') ?><span>Acesso ativado</span></div>
                <h2>Tudo pronto, <?= e((string)($record['name'] ?? 'aluno')) ?>!</h2>
                <p class="login-form-intro">Seu usuário e sua senha foram definidos. O acesso continua conectado ao histórico da Emma.</p>
                <a class="btn btn-primary login-submit" href="/portal/index.php">Entrar no meu portal</a>
            <?php elseif (!$record): ?>
                <div class="error" role="alert">Este link expirou ou já foi utilizado.</div>
                <h2>Solicite um novo acesso</h2>
                <p class="login-form-intro">Envie a palavra <strong>ACESSO</strong> para a Emma no WhatsApp. Ela enviará um novo link seguro.</p>
                <a class="btn btn-secondary login-submit" href="/login.php">Voltar ao login</a>
            <?php else: ?>
                <div class="login-secure-label"><?= ui_icon('shield', 'icon-sm') ?><span>Primeiro acesso seguro</span></div>
                <h2>Olá, <?= e((string)$record['name']) ?></h2>
                <p class="login-form-intro">Escolha um nome de usuário fácil de lembrar. Você também continuará podendo entrar pelo telefone ou e-mail.</p>
                <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="form-row login-field">
                        <label for="username">Nome de usuário</label>
                        <input id="username" name="username" required autocomplete="username" value="<?= e($selectedUsername) ?>" placeholder="ex.: rafaela.silveira">
                        <small class="form-help">Entre 4 e 40 caracteres. Use letras, números, ponto, hífen ou sublinhado.</small>
                    </div>
                    <div class="form-row login-field">
                        <label for="email">E-mail opcional</label>
                        <input id="email" type="email" name="email" autocomplete="email" value="<?= e((string)($_POST['email'] ?? $record['email'] ?? '')) ?>" placeholder="voce@exemplo.com">
                    </div>
                    <div class="form-row login-field">
                        <label for="password">Crie sua senha</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="Mínimo 8 caracteres">
                    </div>
                    <div class="form-row login-field">
                        <label for="password_confirmation">Confirme sua senha</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Repita a senha">
                    </div>
                    <small class="form-help">Use letras maiúsculas, minúsculas e pelo menos um número.</small>
                    <button class="btn btn-primary login-submit" type="submit">Ativar meu acesso</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>
