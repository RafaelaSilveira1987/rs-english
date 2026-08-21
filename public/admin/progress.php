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
$attentionOnly = (string)($_GET['attention'] ?? '') === '1';

$severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
$attentionRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['attention_reasons'] ?? []) !== []));
usort($attentionRows, static function (array $a, array $b) use ($severityRank): int {
    $aSeverity = (string)($a['attention_reasons'][0]['severity'] ?? 'low');
    $bSeverity = (string)($b['attention_reasons'][0]['severity'] ?? 'low');
    $rankCompare = ($severityRank[$aSeverity] ?? 9) <=> ($severityRank[$bSeverity] ?? 9);
    if ($rankCompare !== 0) return $rankCompare;
    return ((int)($b['days_since_activity'] ?? 0)) <=> ((int)($a['days_since_activity'] ?? 0));
});

$filtered = array_values(array_filter($rows, static function (array $row) use ($q, $level, $status, $attentionOnly): bool {
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
    if ($attentionOnly && ($row['attention_reasons'] ?? []) === []) return false;
    return true;
}));

usort($filtered, static function (array $a, array $b) use ($severityRank): int {
    $aReason = $a['attention_reasons'][0] ?? null;
    $bReason = $b['attention_reasons'][0] ?? null;
    if ($aReason || $bReason) {
        $aRank = $aReason ? ($severityRank[(string)$aReason['severity']] ?? 9) : 10;
        $bRank = $bReason ? ($severityRank[(string)$bReason['severity']] ?? 9) : 10;
        if ($aRank !== $bRank) return $aRank <=> $bRank;
    }
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
        <p class="label">Cada indicador é alimentado por eventos reais vindos do WhatsApp, painel web, áudios, atividades e diagnóstico.</p>
    </div>
    <div class="hero-stat"><strong><?= number_format((float)$summary['active_7d_percent'], 0) ?>%</strong><span>dos alunos estudaram nos últimos 7 dias</span></div>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Alunos cadastrados</div><div class="metric"><?= (int)$summary['students_total'] ?></div><div class="metric-sub"><?= (int)$summary['active_7d'] ?> ativos em 7 dias</div></div><div class="metric-icon"><?= ui_icon('students') ?></div></article>
    <article class="card metric-card"><div><div class="label">Ativos em 30 dias</div><div class="metric"><?= (int)$summary['active_30d'] ?></div><div class="metric-sub"><?= number_format((float)$summary['active_30d_percent'], 0) ?>% da base</div></div><div class="metric-icon"><?= ui_icon('streak') ?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/admin/progress.php?attention=1"></a><div><div class="label">Precisam de atenção</div><div class="metric"><?= (int)$summary['needs_attention'] ?></div><div class="metric-sub"><?= (int)$summary['attention_high'] ?> casos prioritários · <?= (int)$summary['attention_medium'] ?> médios</div></div><div class="metric-icon"><?= ui_icon('health') ?></div></article>
    <article class="card metric-card"><div><div class="label">Tempo de estudo acumulado</div><div class="metric"><?= number_format(((int)$summary['study_minutes_total']) / 60, 1, ',', '.') ?>h</div><div class="metric-sub">Somatório dos eventos mensuráveis da base</div></div><div class="metric-icon"><?= ui_icon('history') ?></div></article>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Média de competências</div><div class="metric"><?= number_format((float)$summary['skill_average'], 0) ?>%</div><div class="metric-sub">Somente competências com evidências</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Meta semanal média</div><div class="metric"><?= number_format((float)$summary['weekly_goal_average'], 0) ?>%</div><div class="metric-sub">Minutos, atividades e palavras</div></div><div class="metric-icon"><?= ui_icon('target') ?></div></article>
    <article class="card metric-card"><div><div class="label">Diagnósticos concluídos</div><div class="metric"><?= number_format((float)$summary['diagnostic_completion_percent'], 0) ?>%</div><div class="metric-sub"><?= (int)$summary['diagnostic_completed'] ?> aluno(s) classificados</div></div><div class="metric-icon"><?= ui_icon('diagnostic') ?></div></article>
    <article class="card metric-card"><div><div class="label">Erros recorrentes</div><div class="metric"><?= (int)$summary['recurring_errors'] ?></div><div class="metric-sub">Pontos ainda abertos em toda a base</div></div><div class="metric-icon"><?= ui_icon('corrections') ?></div></article>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Conclusão de atividades</div><div class="metric"><?= number_format((float)$summary['activity_completion_percent'], 0) ?>%</div><div class="metric-sub">Sobre todas as atividades atribuídas</div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><div><div class="label">Sessões em 7 dias</div><div class="metric"><?= (int)$summary['sessions_7d'] ?></div><div class="metric-sub">Conversas e avaliações registradas</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
    <article class="card metric-card"><div><div class="label">Palavras dominadas</div><div class="metric"><?= (int)$summary['words_mastered'] ?></div><div class="metric-sub">Total consolidado da base</div></div><div class="metric-icon"><?= ui_icon('vocabulary') ?></div></article>
    <article class="card metric-card"><div><div class="label">Atualização dos dados</div><div class="metric">Diária</div><div class="metric-sub">Além da atualização após cada evento</div></div><div class="metric-icon"><?= ui_icon('teacher') ?></div></article>
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
    <div class="panel-head"><div><h2>Alunos que precisam de atenção</h2><p>Priorização automática por inatividade, diagnóstico interrompido, erros recorrentes, atividades pendentes e meta semanal.</p></div><a class="btn btn-secondary btn-sm" href="/admin/progress.php?attention=1">Ver somente alertas</a></div>
    <?php if (!$attentionRows): ?>
        <div class="empty-state compact"><h3>Nenhum alerta pedagógico</h3><p>A base não possui situações que exigem acompanhamento neste momento.</p></div>
    <?php else: ?>
        <div class="attention-grid">
            <?php foreach (array_slice($attentionRows, 0, 12) as $student):
                $firstReason = $student['attention_reasons'][0];
                $recommendation = $student['recommendation'] ?? [];
            ?>
                <article class="attention-card severity-<?= e((string)$firstReason['severity']) ?>">
                    <div class="attention-card-head"><div><strong><?= e((string)$student['name']) ?></strong><span><?= e((string)($student['phone'] ?: $student['email'] ?: 'Sem contato')) ?></span></div><span class="badge <?= e(progress_engagement_class((string)$student['engagement_status'])) ?>"><?= e((string)$student['overall_level']) ?></span></div>
                    <ul><?php foreach (array_slice($student['attention_reasons'], 0, 3) as $reason): ?><li><?= e((string)$reason['label']) ?></li><?php endforeach; ?></ul>
                    <p><b>Próxima ação:</b> <?= e((string)($recommendation['title'] ?? 'Revisar acompanhamento')) ?></p>
                    <a class="btn btn-secondary btn-sm" href="/student.php?id=<?= e((string)$student['id']) ?>">Abrir aluno</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel section-gap">
    <div class="panel-head"><div><h2>Progresso por aluno</h2><p><?= count($filtered) ?> aluno(s) no filtro atual. Os valores são consolidados da mesma fonte exibida no painel individual.</p></div></div>
    <form class="search-bar progress-filter" method="get">
        <input name="q" value="<?= e($q) ?>" placeholder="Buscar nome, telefone ou e-mail">
        <select name="level"><option value="">Todos os níveis</option><?php foreach (['PRE-A1','A1','A2','B1','B2','C1','C2'] as $item): ?><option value="<?= e($item) ?>" <?= $level === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select>
        <select name="status"><option value="">Todas as situações</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativos</option><option value="attention" <?= $status === 'attention' ? 'selected' : '' ?>>Atenção por frequência</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativos</option><option value="not_started" <?= $status === 'not_started' ? 'selected' : '' ?>>Não iniciaram</option></select>
        <label class="filter-check"><input type="checkbox" name="attention" value="1" <?= $attentionOnly ? 'checked' : '' ?>> Apenas alertas</label>
        <button class="btn btn-primary">Filtrar</button>
    </form>

    <?php if (!$filtered): ?>
        <div class="empty-state"><h3>Nenhum aluno encontrado</h3><p>Ajuste os filtros para consultar outro grupo.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Situação</th><th>Nível</th><th>Competências</th><th>Meta semanal</th><th>Estudo 30d</th><th>Atividades</th><th>Correções</th><th>Última atividade</th></tr></thead><tbody>
        <?php foreach ($filtered as $student): $reason = $student['attention_reasons'][0] ?? null; ?>
            <tr>
                <td><a class="table-link" href="/student.php?id=<?= e((string)$student['id']) ?>"><strong><?= e((string)$student['name']) ?></strong><div class="label"><?= e((string)($student['phone'] ?: $student['email'] ?: 'Sem contato')) ?></div></a></td>
                <td><span class="badge <?= e(progress_engagement_class((string)$student['engagement_status'])) ?>"><?= e(progress_engagement_label((string)$student['engagement_status'])) ?></span><?php if ($reason): ?><div class="label attention-label"><?= e((string)$reason['label']) ?></div><?php endif; ?></td>
                <td><span class="badge <?= e(ui_level_class((string)$student['overall_level'])) ?>"><?= e((string)$student['overall_level']) ?></span></td>
                <td><strong><?= number_format((float)$student['skill_average'], 0) ?>%</strong><div class="label"><?= (int)$student['skills_measured'] ?>/8 · <?= (int)$student['skill_evidence_count'] ?> evidências</div></td>
                <td><strong><?= number_format((float)$student['week']['goal_percent'], 0) ?>%</strong><div class="progress slim"><span data-progress="<?= (float)$student['week']['goal_percent'] ?>"></span></div></td>
                <td><strong><?= (int)$student['study_minutes_30d'] ?> min</strong><div class="label"><?= (int)$student['active_days_30d'] ?> dias ativos</div></td>
                <td><strong><?= (int)$student['activities_completed'] ?>/<?= (int)$student['activities_total'] ?></strong><div class="label">média <?= number_format((float)$student['activity_average_score'], 0) ?>%</div></td>
                <td><strong><?= (int)$student['corrections_recurring'] ?> recorrentes</strong><div class="label"><?= number_format((float)$student['corrections_resolved_rate'], 0) ?>% resolvidas</div></td>
                <td><?= e(ui_relative_date($student['last_activity_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
