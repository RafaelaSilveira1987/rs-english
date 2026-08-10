<?php
require_once __DIR__ . '/../src/auth.php';
require_login();
$pageTitle = $pageTitle ?? 'RS English';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
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
                <small>English Coach</small>
            </div>
        </div>

        <nav>
            <a href="/index.php">Dashboard</a>
            <a href="/students.php">Alunos</a>
        </nav>

        <div class="sidebar-footer">
            <a href="/logout.php">Sair</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <p>Acompanhamento pedagógico e evolução</p>
            </div>
        </header>
