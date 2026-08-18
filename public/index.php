<?php
declare(strict_types=1);
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';
require_once __DIR__.'/../src/ui.php';
require_teacher_or_admin();
$pdo=db();

$stats=[
    'students'=>(int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn(),
    'sessions_7d'=>(int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE created_at>=NOW()-INTERVAL '7 days'")->fetchColumn(),
    'activities'=>(int)$pdo->query("SELECT COUNT(*) FROM student_activities WHERE status='pending'")->fetchColumn(),
    'diagnostics'=>(int)$pdo->query("SELECT COUNT(*) FROM student_profiles WHERE diagnostic_status IN ('pending','in_progress')")->fetchColumn(),
    'errors_due'=>(int)$pdo->query("SELECT COUNT(*) FROM student_errors WHERE status='learning' AND (next_review_at IS NULL OR next_review_at<=NOW())")->fetchColumn(),
    'words_due'=>(int)$pdo->query("SELECT COUNT(*) FROM student_vocabulary WHERE status IN ('learning','review') AND (next_review_at IS NULL OR next_review_at<=NOW())")->fetchColumn(),
    'active_conversations'=>(int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE status='active' AND mode='conversation'")->fetchColumn(),
    'reports'=>(int)$pdo->query("SELECT COUNT(*) FROM weekly_reports WHERE week_start>=CURRENT_DATE-INTERVAL '28 days'")->fetchColumn(),
];

$recent=$pdo->query("
SELECT
    s.id,s.name,s.phone,s.email,
    COALESCE(sp.overall_level,'PRE-A1') AS overall_level,
    COALESCE(sp.xp,0) AS xp,
    COALESCE(sp.grammar_score,0) AS grammar_score,
    COALESCE(sp.vocabulary_score,0) AS vocabulary_score,
    COALESCE(sp.diagnostic_status,'pending') AS diagnostic_status,
    sp.last_study_at,
    (SELECT COUNT(*) FROM student_errors se WHERE se.student_id=s.id AND se.status='learning' AND (se.next_review_at IS NULL OR se.next_review_at<=NOW())) AS errors_due,
    (SELECT COUNT(*) FROM student_vocabulary sv WHERE sv.student_id=s.id AND sv.status IN ('learning','review') AND (sv.next_review_at IS NULL OR sv.next_review_at<=NOW())) AS vocab_due,
    (SELECT COUNT(*) FROM student_activities sa WHERE sa.student_id=s.id AND sa.status='pending') AS activities_due,
    (SELECT COUNT(*) FROM sessions sx WHERE sx.student_id=s.id AND sx.created_at>=NOW()-INTERVAL '30 days') AS sessions_30d
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id=s.id
WHERE s.status='active'
ORDER BY COALESCE(sp.last_study_at,s.created_at) DESC
LIMIT 10
")->fetchAll();

$pageTitle='Dashboard';
$pageSubtitle='Indicadores reais da operação e alunos que precisam de acompanhamento.';
require __DIR__.'/../templates/header.php';
?>

<section class="hero">
    <div class="hero-copy">
        <span class="badge dark">RS English Intelligence</span>
        <h2>Ensino personalizado com visão clara de progresso.</h2>
        <p class="label">Acompanhe diagnósticos, conversações, revisões e atividades com dados centralizados.</p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="/students.php"><?= ui_icon('students','icon-sm') ?> Ver alunos</a>
            <a class="btn btn-secondary" href="/reports.php"><?= ui_icon('reports','icon-sm') ?> Relatórios</a>
        </div>
    </div>
    <div class="hero-stat"><strong><?= $stats['active_conversations'] ?></strong><span>conversas ativas agora</span></div>
</section>

<section class="cards">
    <article class="card metric-card"><a class="metric-link" href="/students.php" aria-label="Ver alunos"></a><div><div class="label">Alunos ativos</div><div class="metric"><?= $stats['students'] ?></div><div class="metric-sub">Base atual da plataforma</div></div><div class="metric-icon"><?= ui_icon('students') ?></div></article>
    <article class="card metric-card"><div><div class="label">Sessões nos últimos 7 dias</div><div class="metric"><?= $stats['sessions_7d'] ?></div><div class="metric-sub">Conversas e avaliações registradas</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/activities.php" aria-label="Ver atividades"></a><div><div class="label">Atividades pendentes</div><div class="metric"><?= $stats['activities'] ?></div><div class="metric-sub">Exercícios aguardando conclusão</div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/students.php?diagnostic=pending" aria-label="Ver diagnósticos"></a><div><div class="label">Diagnósticos em aberto</div><div class="metric"><?= $stats['diagnostics'] ?></div><div class="metric-sub">Pendentes ou em andamento</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
</section>

<section class="cards">
    <article class="card metric-card"><div><div class="label">Revisões gramaticais</div><div class="metric"><?= $stats['errors_due'] ?></div><div class="metric-sub">Erros programados para revisão</div></div><div class="metric-icon"><?= ui_icon('bot') ?></div></article>
    <article class="card metric-card"><div><div class="label">Vocabulário para revisar</div><div class="metric"><?= $stats['words_due'] ?></div><div class="metric-sub">Palavras em ciclo de aprendizagem</div></div><div class="metric-icon"><?= ui_icon('vocabulary') ?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/reports.php" aria-label="Ver relatórios"></a><div><div class="label">Relatórios em 28 dias</div><div class="metric"><?= $stats['reports'] ?></div><div class="metric-sub">Resumos pedagógicos recentes</div></div><div class="metric-icon"><?= ui_icon('reports') ?></div></article>
    <article class="card metric-card"><div><div class="label">Conversações ativas</div><div class="metric"><?= $stats['active_conversations'] ?></div><div class="metric-sub">Sessões ainda não encerradas</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Alunos recentes</h2><p>Informações vinculadas ao perfil, sessões e pendências reais de cada aluno.</p></div><a class="btn btn-secondary btn-sm" href="/students.php">Ver todos <?= ui_icon('arrow','icon-sm') ?></a></div>
    <?php if(!$recent): ?>
        <div class="empty-state"><div class="empty-state-icon"><?= ui_icon('students') ?></div><h3>Nenhum aluno cadastrado</h3><p>Os alunos aparecerão aqui assim que iniciarem o diagnóstico ou forem cadastrados.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Nível</th><th>Diagnóstico</th><th>Competências</th><th>Pendências</th><th>Sessões 30d</th><th>Último estudo</th></tr></thead><tbody>
    <?php foreach($recent as $student): ?>
        <tr>
            <td><a class="table-link" href="/student.php?id=<?= e($student['id']) ?>"><strong><?= e($student['name']) ?></strong><div class="label"><?= e($student['phone'] ?: $student['email'] ?: 'Sem contato') ?></div></a></td>
            <td><span class="badge <?= e(ui_level_class($student['overall_level'])) ?>"><?= e($student['overall_level']) ?></span></td>
            <td><span class="badge <?= e(ui_status_class($student['diagnostic_status'])) ?>"><?= e(ui_status_label($student['diagnostic_status'])) ?></span></td>
            <td><div class="label">Grammar <?= number_format((float)$student['grammar_score'],0) ?>% · Vocabulary <?= number_format((float)$student['vocabulary_score'],0) ?>%</div></td>
            <td><span class="badge warning"><?= (int)$student['errors_due']+(int)$student['vocab_due']+(int)$student['activities_due'] ?> itens</span></td>
            <td><strong><?= (int)$student['sessions_30d'] ?></strong></td>
            <td><?= e(ui_relative_date($student['last_study_at'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
