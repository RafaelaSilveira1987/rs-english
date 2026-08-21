<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/ui.php';
require_login();

$pageTitle = $pageTitle ?? 'RS English';
$pageSubtitle = $pageSubtitle ?? null;
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function nav_active_paths(array $paths): string
{
    global $currentPath;
    foreach ($paths as $path) {
        if ($currentPath === $path || str_starts_with($currentPath, rtrim($path, '/') . '/')) return 'active';
    }
    return '';
}

function nav_item(string $href, string $label, string $icon, array $paths): void
{
    echo '<a class="'.nav_active_paths($paths).'" href="'.e($href).'">';
    echo ui_icon($icon, 'nav-icon');
    echo '<span>'.e($label).'</span>';
    echo '</a>';
}

$user = current_user();
$role = $user['role'] ?? (!empty($_SESSION['legacy_admin']) ? 'admin' : null);
$isStudentPortal = $role === 'student';
$defaultSubtitle = $isStudentPortal
    ? 'Seu aprendizado, progresso e prática em um só lugar.'
    : 'Acompanhe alunos, desempenho e evolução pedagógica.';
$homeHref = $isStudentPortal ? '/portal/index.php' : '/index.php';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f9ff">
<meta name="color-scheme" content="light">
<title><?= e($pageTitle) ?> — RS English</title>
<link rel="icon" href="/assets/images/rs-english-mark-transparent.png" type="image/png">
<link rel="stylesheet" href="/assets/css/app.css?v=14.0">
</head>
<body class="role-<?= e((string)$role) ?>">
<div class="sidebar-overlay" data-sidebar-overlay></div>
<div class="layout">

<aside class="sidebar" aria-label="Navegação principal">
    <a class="brand" href="<?= e($homeHref) ?>" aria-label="RS English — início">
        <img src="/assets/images/rs-english-horizontal-transparent.png" alt="RS English">
    </a>

    <div class="sidebar-scroll">
    <?php if ($isStudentPortal): ?>
        <div class="nav-section">Meu aprendizado</div>
        <nav>
            <?php nav_item('/portal/index.php', 'Início', 'dashboard', ['/portal/index.php']); ?>
            <?php nav_item('/portal/practice.php', 'Conversar com Emma', 'practice', ['/portal/practice.php']); ?>
            <?php nav_item('/portal/diagnostic.php', 'Meu diagnóstico', 'diagnostic', ['/portal/diagnostic.php']); ?>
            <?php nav_item('/portal/activities.php', 'Atividades', 'activities', ['/portal/activities.php', '/portal/activity.php']); ?>
            <?php nav_item('/portal/corrections.php', 'Correções', 'corrections', ['/portal/corrections.php']); ?>
            <?php nav_item('/portal/vocabulary.php', 'Vocabulário', 'vocabulary', ['/portal/vocabulary.php']); ?>
            <?php nav_item('/portal/progress.php', 'Meu progresso', 'progress', ['/portal/progress.php']); ?>
            <?php nav_item('/portal/history.php', 'Histórico', 'history', ['/portal/history.php']); ?>
            <?php nav_item('/portal/onboarding.php', 'Meu plano', 'plan', ['/portal/onboarding.php']); ?>
            <?php nav_item('/portal/profile.php', 'Meu perfil', 'profile', ['/portal/profile.php']); ?>
        </nav>
    <?php else: ?>
        <div class="nav-section">Visão geral</div>
        <nav>
            <?php nav_item('/index.php', 'Dashboard', 'dashboard', ['/index.php']); ?>
            <?php nav_item('/students.php', 'Alunos', 'students', ['/students.php', '/student.php']); ?>
        </nav>

        <div class="nav-section">Ensino</div>
        <nav>
            <?php nav_item('/activities.php', 'Atividades', 'activities', ['/activities.php']); ?>
            <?php nav_item('/knowledge.php', 'Conteúdos', 'knowledge', ['/knowledge.php', '/knowledge-source.php']); ?>
            <?php nav_item('/curriculum.php', 'Currículo', 'curriculum', ['/curriculum.php']); ?>
        </nav>

        <div class="nav-section">Análise</div>
        <nav>
            <?php nav_item('/admin/progress.php', 'Progresso geral', 'progress', ['/admin/progress.php']); ?>
            <?php nav_item('/reports.php', 'Relatórios', 'reports', ['/reports.php']); ?>
        </nav>

        <?php if (is_admin()): ?>
            <div class="nav-section">Administração</div>
            <nav>
                <?php nav_item('/admin/users.php', 'Usuários', 'users', ['/admin/users.php']); ?>
                <?php nav_item('/admin/accesses.php', 'Acessos dos alunos', 'password', ['/admin/accesses.php']); ?>
                <?php nav_item('/admin/teacher-settings.php', 'Professor IA', 'bot', ['/admin/teacher-settings.php']); ?>
                <?php nav_item('/admin/system-health.php', 'Saúde do sistema', 'health', ['/admin/system-health.php']); ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <div class="sidebar-user">
        <div class="avatar avatar-sm"><?= e(ui_initials($user['name'] ?? 'RS English')) ?></div>
        <div class="sidebar-user-copy">
            <strong><?= e($user['name'] ?? 'Administrador') ?></strong>
            <span><?= e(ui_role_label((string)$role)) ?></span>
        </div>
    </div>

    <div class="sidebar-footer">
        <?php if ($user): ?>
            <a class="<?= nav_active_paths(['/change-password.php']) ?>" href="/change-password.php"><?= ui_icon('password', 'nav-icon') ?><span>Alterar senha</span></a>
        <?php endif; ?>
        <a href="/logout.php"><?= ui_icon('logout', 'nav-icon') ?><span>Sair</span></a>
    </div>
</aside>

<main class="main">
<header class="topbar">
    <div class="topbar-heading">
        <button class="mobile-menu" data-mobile-menu type="button" aria-label="Abrir menu"><?= ui_icon('menu') ?></button>
        <div>
            <div class="eyebrow"><?= $isStudentPortal ? 'Portal do aluno' : 'Portal pedagógico' ?></div>
            <h1><?= e($pageTitle) ?></h1>
            <p><?= e($pageSubtitle ?? $defaultSubtitle) ?></p>
        </div>
    </div>

    <div class="topbar-actions">
        <div class="system-pill"><span class="status-dot"></span><span>Sistema online</span></div>
        <div class="user-pill">
            <div class="avatar avatar-xs"><?= e(ui_initials($user['name'] ?? 'RS')) ?></div>
            <div><strong><?= e(ui_first_name($user['name'] ?? 'Usuário')) ?></strong><span><?= e(ui_role_label((string)$role)) ?></span></div>
        </div>
    </div>
</header>
