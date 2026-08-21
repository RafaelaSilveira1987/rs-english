<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/progress.php';

require_teacher_or_admin();

$rows = progress_all_student_metrics(true);
$summary = progress_admin_summary($rows);
$daily = progress_admin_daily_activity(14);

$q = trim((string)($_GET['q'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$filtered = array_values(array_filter($rows, static function(array $row) use ($q, $level, $status): bool {
    if ($q !== '') {
        $haystack = mb_strtolower(implode(' ', [
            (string)($row['name'] ?? ''),
            (string)($row['phone'] ?? ''),
            (string)($row['email'] ?? ''),
        ]));
        if (!str_contains($haystack, mb_strtolower($q))) return false;
    }
    if ($level !== '' && (string)($row['overall_level'] ?? '') !== $level) return false;
    if ($status !== '' && (string)($row['engagement_status'] ?? '') !== $status) return false;
    return true;
}));

usort($filtered, static function(array $a, array $b): int {
    $rank = ['not_started'=>0, 'inactive'=>1, 'attention'=>2, 'active'=>3];
    $ra = $rank[$a['engagement_status'] ?? 'not_started'] ?? 0;
    $rb = $rank[$b['engagement_status'] ?? 'not_started'] ?? 0;
    if ($ra !== $rb) return $ra <=> $rb;
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$maxDaily = max(1, ...array_map(static fn($d) => (int)$d['sessions'] + (int)$d['activities'], $daily));
$maxLevel = max(1, ...array_values($summary['levels']));

$pageTitle = 'Progresso geral';
$pageSubtitle = 'Indicadores calculados a partir das interações, atividades, vocabulário, diagnóstico e histórico reais dos alunos.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="hero">
    <div class="hero-copy">
        <span class="badge dark">Visão pedagógica consolidada</span>
        <h2>Veja quem está avançando e quem precisa de atenção.</h2>
        <p class="label">Os números abaixo são calculados diretamente dos registros da plataforma, sem preenchimento manual.</p>
    </div>
    <div class="hero-stat"><strong><?= number_format((float)$summary['active_7d_percent'], 0) ?>%</strong><span>dos alunos ativos nos últimos 7 dias</span></div>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Alunos ativos</div><div class="metric"><?= (int)$summary['students_total'] ?></div><div class="metric-sub"><?= (int)$summary['active_7d'] ?> estudaram nos últimos 7 dias</div></div><div class="metric-icon"><?= ui_icon('students') ?></div></article>
    <article class="card metric-card"><div><div class="label">Média de competências</div><div class="metric"><?= number_format((float)$summary['skill_average'], 0) ?>%</div><div class="metric-sub">Somente competências já avaliadas</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Meta semanal média</div><div class="metric"><?= number_format((float)$summary['weekly_goal_average'], 0) ?>%</div><div class="metric-sub">Minutos, atividades e palavras</div></div><div class="metric-icon"><?= ui_icon('target') ?></div></article>
    <article class="card metric-card"><div><div class="label">Precisam de atenção</div><div class="metric"><?= (int)$summary['needs_attention'] ?></div><div class="metric-sub">Sem início ou com baixa frequência</div></div><div class="metric-icon"><?= ui_icon('health') ?></div></article>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Diagnósticos concluídos</div><div class="metric"><?= number_format((float)$summary['diagnostic_completion_percent'], 0) ?>%</div><div class="metric-sub"><?= (int)$summary['diagnostic_completed'] ?> aluno(s)</div></div><div class="metric-icon"><?= ui_icon('diagnostic') ?></div></article>
    <article class="card metric-card"><div><div class="label">Conclusão de atividades</div><div class="metric"><?= number_format((float)$summary['activity_completion_percent'], 0) ?>%</div><div class="metric-sub">Sobre todas as atividades atribuídas</div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><div><div class="label">Sessões em 7 dias</div><div class="metric"><?= (int)$summary['sessions_7d'] ?></div><div class="metric-sub">Conversas e avaliações registradas</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
    <article class="card metric-card"><div><div class="label">Palavras dominadas</div><div class="metric"><?= (int)$summary['words_mastered'] ?></div><div class="metric-sub">Total consolidado da base</div></div><div class="metric-icon"><?= ui_icon('vocabulary') ?></div></article>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Movimento dos últimos 14 dias</h2><p>Sessões e atividades concluídas por dia.</p></div></div>
        <div class="weekly-chart admin-activity-chart">
            <?php foreach ($daily as $day): $total = (int)$day['sessions'] + (int)$day['activities']; $height = max(7, (int)round(($total / $maxDaily) * 100)); ?>
                <div class="weekly-bar-column" title="<?= e((string)$day['day']) ?>: <?= (int)$day['sessions'] ?> sessões, <?= (int)$day['activities'] ?> atividades">
                    <span class="weekly-value"><?= $total ?></span>
                    <div class="weekly-bar-track"><span style="height:<?= $height ?>%"></span></div>
                    <strong><?= e((new DateTimeImmutable((string)$day['day']))->format('d/m')) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Distribuição por nível</h2><p>Nível QECR atual registrado no perfil.</p></div></div>
        <div class="level-distribution">
            <?php foreach ($summary['levels'] as $levelName => $count): $pct = ($count / $maxLevel) * 100; ?>
                <div class="level-distribution-row">
                    <span class="badge <?= e(ui_level_class((string)$levelName)) ?>"><?= e((string)$levelName) ?></span>
                    <div class="progress"><span data-progress="<?= round($pct, 1) ?>"></span></div>
                    <strong><?= (int)$count ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="panel section-gap">
    <div class="panel-head"><div><h2>Progresso por aluno</h2><p><?= count($filtered) ?> aluno(s) no filtro atual. A situação é definida pela data real da última atividade.</p></div></div>
    <form class="search-bar progress-filter" method="get">
        <input name="q" value="<?= e($q) ?>" placeholder="Buscar nome, telefone ou e-mail">
        <select name="level"><option value="">Todos os níveis</option><?php foreach (['PRE-A1','A1','A2','B1','B2','C1','C2'] as $item): ?><option value="<?= e($item) ?>" <?= $level === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select>
        <select name="status"><option value="">Todas as situações</option><option value="active" <?= $status==='active'?'selected':'' ?>>Ativos</option><option value="attention" <?= $status==='attention'?'selected':'' ?>>Atenção</option><option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inativos</option><option value="not_started" <?= $status==='not_started'?'selected':'' ?>>Não iniciaram</option></select>
        <button class="btn btn-primary">Filtrar</button>
    </form>

    <?php if (!$filtered): ?>
        <div class="empty-state"><h3>Nenhum aluno encontrado</h3><p>Ajuste os filtros para consultar outro grupo.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Situação</th><th>Nível</th><th>Competências</th><th>Meta semanal</th><th>Atividades</th><th>Vocabulário</th><th>Sessões 30d</th><th>Última atividade</th></tr></thead><tbody>
        <?php foreach ($filtered as $student): ?>
            <tr>
                <td><a class="table-link" href="/student.php?id=<?= e((string)$student['id']) ?>"><strong><?= e((string)$student['name']) ?></strong><div class="label"><?= e((string)($student['phone'] ?: $student['email'] ?: 'Sem contato')) ?></div></a></td>
                <td><span class="badge <?= e(progress_engagement_class((string)$student['engagement_status'])) ?>"><?= e(progress_engagement_label((string)$student['engagement_status'])) ?></span></td>
                <td><span class="badge <?= e(ui_level_class((string)$student['overall_level'])) ?>"><?= e((string)$student['overall_level']) ?></span></td>
                <td><strong><?= number_format((float)$student['skill_average'], 0) ?>%</strong><div class="label"><?= (int)$student['skills_measured'] ?>/8 medidas</div></td>
                <td><strong><?= number_format((float)$student['week']['goal_percent'], 0) ?>%</strong><div class="progress slim"><span data-progress="<?= (float)$student['week']['goal_percent'] ?>"></span></div></td>
                <td><strong><?= (int)$student['activities_completed'] ?>/<?= (int)$student['activities_total'] ?></strong><div class="label"><?= number_format((float)$student['activity_completion_rate'],0) ?>%</div></td>
                <td><strong><?= (int)$student['vocabulary_mastered'] ?></strong><div class="label">de <?= (int)$student['vocabulary_total'] ?> · <?= number_format((float)$student['vocabulary_mastery_rate'],0) ?>%</div></td>
                <td><strong><?= (int)$student['sessions_30d'] ?></strong></td>
                <td><?= e(ui_relative_date($student['last_activity_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
