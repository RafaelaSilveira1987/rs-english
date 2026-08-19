<?php
declare(strict_types=1);
require_once __DIR__.'/../src/auth.php';
require_once __DIR__.'/../src/ui.php';

if (is_logged_in()) {
    header('Location:'.post_login_redirect());
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) verify_csrf();
    $login = trim($_POST['login'] ?? '');

    if (function_exists('login_is_rate_limited') && login_is_rate_limited($login)) {
        $error = 'Muitas tentativas. Aguarde 15 minutos e tente novamente.';
    } elseif (attempt_login($login, $_POST['password'] ?? '')) {
        header('Location:'.post_login_redirect());
        exit;
    } else {
        $error = 'Usuário ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f8ff">
<meta name="color-scheme" content="light">
<title>Entrar — RS English</title>
<link rel="icon" href="/assets/images/rs-english-mark-transparent.png" type="image/png">
<link rel="stylesheet" href="/assets/css/app.css?v=11.0">
</head>
<body class="login-body">
<div class="login-page login-page-redesign">
    <section class="login-visual" aria-label="Apresentação do RS English">
        <div class="login-ambient login-ambient-one" aria-hidden="true"></div>
        <div class="login-ambient login-ambient-two" aria-hidden="true"></div>
        <div class="login-circuit" aria-hidden="true"></div>

        <div class="login-brand">
            <img class="login-brand-logo" src="/assets/images/rs-english-horizontal-transparent.png" alt="RS English">

            <div class="login-welcome-pill">
                <?= ui_icon('sparkles', 'icon-sm') ?>
                <span>Seja bem-vindo(a) ao RS English</span>
            </div>

            <h1 class="login-hero-title">
                Aprendizado de inglês
                <span>com acompanhamento inteligente.</span>
            </h1>

            <p class="login-hero-copy">
                Diagnóstico adaptativo, prática de conversação, correções personalizadas e evolução acompanhada em um único ambiente.
            </p>

            <div class="login-feature-list" aria-label="Recursos da plataforma">
                <div class="login-feature-card">
                    <span class="login-feature-icon"><?= ui_icon('chat') ?></span>
                    <span><strong>Conversação</strong><small>com a Emma</small></span>
                </div>
                <div class="login-feature-card">
                    <span class="login-feature-icon"><?= ui_icon('reports') ?></span>
                    <span><strong>Progresso por</strong><small>competência</small></span>
                </div>
                <div class="login-feature-card">
                    <span class="login-feature-icon"><?= ui_icon('target') ?></span>
                    <span><strong>Plano de estudo</strong><small>individual</small></span>
                </div>
            </div>

            <div class="login-security-note">
                <?= ui_icon('shield', 'icon-sm') ?>
                <span>Ambiente seguro e protegido para seus dados.</span>
            </div>
        </div>
    </section>

    <section class="login-form-side">
        <form class="login-box" method="post" novalidate>
            <?php if (function_exists('csrf_field')): ?><?= csrf_field() ?><?php endif; ?>

            <img class="login-logo-mobile" src="/assets/images/rs-english-horizontal-transparent.png" alt="RS English">

            <div class="login-secure-label">
                <?= ui_icon('shield', 'icon-sm') ?>
                <span>Acesso seguro</span>
            </div>

            <h2>Bem-vindo</h2>
            <p class="login-form-intro">Entre com seus dados para acessar o portal.</p>

            <?php if ($error): ?>
                <div class="error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="form-row login-field">
                <label for="login">Usuário, e-mail ou telefone</label>
                <div class="login-input-wrap">
                    <span class="login-input-icon"><?= ui_icon('user') ?></span>
                    <input id="login" name="login" required autofocus autocomplete="username" placeholder="Digite seu acesso" value="<?= e($_POST['login'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row login-field">
                <label for="password">Senha</label>
                <div class="login-input-wrap">
                    <span class="login-input-icon"><?= ui_icon('lock') ?></span>
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Digite sua senha">
                    <button class="password-toggle" type="button" aria-label="Mostrar senha" aria-pressed="false" data-password-toggle>
                        <?= ui_icon('eye') ?>
                    </button>
                </div>
            </div>

            <button class="btn btn-primary login-submit" type="submit">Entrar no portal</button>

            <div class="login-divider"><span>ou</span></div>

            <div class="login-forgot">
                <a href="/forgot-password.php">Esqueci minha senha</a>
            </div>
        </form>
    </section>
</div>
<script>
document.querySelector('[data-password-toggle]')?.addEventListener('click', function () {
    const input = document.getElementById('password');
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    this.setAttribute('aria-pressed', String(!showing));
    this.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
});
</script>
</body>
</html>
