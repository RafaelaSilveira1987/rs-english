<?php
require_once __DIR__ . '/../src/auth.php';
require_login();

$pageTitle = $pageTitle ?? 'RS English';
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function nav_active_paths(array $paths): string
{
    global $currentPath;
    foreach ($paths as $path) {
        if ($currentPath === $path || str_starts_with($currentPath, rtrim($path, '/') . '/')) {
            return 'active';
        }
    }
    return '';
}

$user = current_user();
$role = $user['role'] ?? (!empty($_SESSION['legacy_admin']) ? 'admin' : null);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#111827">
<title><?= htmlspecialchars($pageTitle) ?> - RS English</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark">RS</div>
        <div>
            <strong>RS English</strong>
            <small>AI English Coach</small>
        </div>
    </div>

    <?php if ($role === 'student'): ?>
        <div class="nav-section">Meu aprendizado</div>
        <nav>
            <a class="<?= nav_active_paths(['/portal/index.php']) ?>" href="/portal/index.php"><span class="nav-dot"></span> Meu progresso</a>
            <a class="<?= nav_active_paths(['/portal/practice.php']) ?>" href="/portal/practice.php"><span class="nav-dot"></span> Praticar</a>
            <a class="<?= nav_active_paths(['/portal/activities.php']) ?>" href="/portal/activities.php"><span class="nav-dot"></span> Atividades</a>
            <a class="<?= nav_active_paths(['/portal/vocabulary.php']) ?>" href="/portal/vocabulary.php"><span class="nav-dot"></span> Vocabulário</a>
            <a class="<?= nav_active_paths(['/portal/onboarding.php']) ?>" href="/portal/onboarding.php"><span class="nav-dot"></span> Meu plano</a>
            <a class="<?= nav_active_paths(['/portal/profile.php']) ?>" href="/portal/profile.php"><span class="nav-dot"></span> Meu perfil</a>
        </nav>
    <?php else: ?>
        <div class="nav-section">Visão geral</div>
        <nav>
            <a class="<?= nav_active_paths(['/index.php']) ?>" href="/index.php"><span class="nav-dot"></span> Dashboard</a>
            <a class="<?= nav_active_paths(['/students.php','/student.php']) ?>" href="/students.php"><span class="nav-dot"></span> Alunos</a>
        </nav>

        <div class="nav-section">Ensino</div>
        <nav>
            <a class="<?= nav_active_paths(['/activities.php']) ?>" href="/activities.php"><span class="nav-dot"></span> Atividades</a>
            <a class="<?= nav_active_paths(['/knowledge.php','/knowledge-source.php']) ?>" href="/knowledge.php"><span class="nav-dot"></span> Conteúdos</a>
            <a class="<?= nav_active_paths(['/curriculum.php']) ?>" href="/curriculum.php"><span class="nav-dot"></span> Currículo</a>
        </nav>

        <div class="nav-section">Análise</div>
        <nav>
            <a class="<?= nav_active_paths(['/reports.php']) ?>" href="/reports.php"><span class="nav-dot"></span> Relatórios</a>
        </nav>

        <?php if (is_admin()): ?>
            <div class="nav-section">Administração</div>
            <nav>
                <a class="<?= nav_active_paths(['/admin/users.php']) ?>" href="/admin/users.php"><span class="nav-dot"></span> Usuários</a>
                <a class="<?= nav_active_paths(['/admin/teacher-settings.php']) ?>" href="/admin/teacher-settings.php"><span class="nav-dot"></span> Professor IA</a>
                <a class="<?= nav_active_paths(['/admin/system-health.php']) ?>" href="/admin/system-health.php"><span class="nav-dot"></span> Saúde do sistema</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <div class="sidebar-footer">
        <?php if ($user): ?>
            <a class="<?= nav_active_paths(['/change-password.php']) ?>" href="/change-password.php"><span class="nav-dot"></span> Alterar senha</a>
        <?php endif; ?>
        <a href="/logout.php"><span class="nav-dot"></span> Sair</a>
    </div>
</aside>

<main class="main">
<header class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="mobile-menu" data-mobile-menu type="button">☰</button>
        <div>
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <p><?= $role === 'student' ? 'Seu aprendizado, progresso e prática em um só lugar.' : 'Aprendizado contínuo, progresso mensurável.' ?></p>
        </div>
    </div>

    <div class="topbar-actions">
        <?php if ($user): ?><span class="badge"><?= htmlspecialchars($user['name']) ?></span><?php endif; ?>
        <span class="badge success">● Sistema online</span>
    </div>
</header>
