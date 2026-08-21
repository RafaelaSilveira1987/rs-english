<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';
require_once __DIR__ . '/../../src/learning.php';

$user = require_student();
$pdo = db();
$studentId = (string)$user['student_id'];
learning_ensure_plan_activities($pdo, $studentId);
$status = trim((string)($_GET['status'] ?? ''));

$sql = <<<'SQL'
    SELECT
        sa.id, sa.status, sa.assigned_at, sa.started_at, sa.completed_at,
        sa.score, sa.xp_earned, sa.attempts, sa.plan_week,
        sa.plan_item_index, sa.available_from, sa.due_date, sa.assignment_source,
        a.title, a.description, a.activity_type, a.skill, a.level,
        a.xp_reward, a.estimated_minutes
    FROM student_activities sa
    JOIN activities a ON a.id = sa.activity_id
    WHERE sa.student_id = :student_id
SQL;
$params = ['student_id' => $studentId];
if (in_array($status, ['pending', 'completed'], true)) {
    $sql .= ' AND sa.status = :status';
    $params['status'] = $status;
} elseif ($status === 'scheduled') {
    $sql .= " AND sa.status = 'pending' AND sa.available_from > CURRENT_DATE";
}
$sql .= " ORDER BY COALESCE(sa.plan_week, 99), COALESCE(sa.plan_item_index, 99), CASE WHEN sa.status = 'pending' THEN 0 ELSE 1 END, COALESCE(sa.completed_at, sa.assigned_at) DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statsStmt = $pdo->prepare(<<<'SQL'
    SELECT
        COUNT(*) FILTER (WHERE status = 'pending' AND (available_from IS NULL OR available_from <= CURRENT_DATE)) AS available,
        COUNT(*) FILTER (WHERE status = 'pending' AND available_from > CURRENT_DATE) AS scheduled,
        COUNT(*) FILTER (WHERE status = 'completed') AS completed,
        COUNT(score) FILTER (WHERE status = 'completed' AND score IS NOT NULL) AS scored,
        COALESCE(SUM(xp_earned) FILTER (WHERE status = 'completed'), 0) AS xp,
        COALESCE(AVG(score) FILTER (WHERE status = 'completed' AND score IS NOT NULL), 0) AS average
    FROM student_activities
    WHERE student_id = :student_id
SQL);
$statsStmt->execute(['student_id' => $studentId]);
$stats = $statsStmt->fetch() ?: [];

$planStmt = $pdo->prepare(<<<'SQL'
    SELECT
        plan_week,
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE status = 'completed') AS completed,
        COUNT(*) FILTER (WHERE status = 'pending' AND (available_from IS NULL OR available_from <= CURRENT_DATE)) AS available,
        MIN(available_from) AS available_from,
        MAX(due_date) AS due_date
    FROM student_activities
    WHERE student_id = :student_id AND plan_week BETWEEN 1 AND 4
    GROUP BY plan_week
    ORDER BY plan_week
SQL);
$planStmt->execute(['student_id' => $studentId]);
$weekRows = [];
foreach ($planStmt->fetchAll() as $weekRow) $weekRows[(int)$weekRow['plan_week']] = $weekRow;

$grouped = [];
foreach ($rows as $row) {
    $key = $row['plan_week'] ? 'week_' . (int)$row['plan_week'] : 'extra';
    $grouped[$key][] = $row;
}

$pageTitle = 'Minhas atividades';
$pageSubtitle = 'Seu plano inicial foi transformado em uma sequência semanal de práticas.';
require __DIR__ . '/../../templates/header.php';
?>
<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Disponíveis agora</div><div class="metric"><?= (int)($stats['available'] ?? 0) ?></div><div class="metric-sub">Prontas para começar</div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><div><div class="label">Programadas</div><div class="metric"><?= (int)($stats['scheduled'] ?? 0) ?></div><div class="metric-sub">Liberadas nas próximas semanas</div></div><div class="metric-icon"><?= ui_icon('plan') ?></div></article>
    <article class="card metric-card"><div><div class="label">Concluídas</div><div class="metric"><?= (int)($stats['completed'] ?? 0) ?></div><div class="metric-sub">No seu histórico</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Média avaliada</div><div class="metric"><?= number_format((float)($stats['average'] ?? 0), 0) ?>%</div><div class="metric-sub"><?= (int)($stats['scored'] ?? 0) ?> atividade(s) com nota</div></div><div class="metric-icon"><?= ui_icon('target') ?></div></article>
</section>

<section class="panel section-gap-sm">
    <div class="panel-head"><div><h2>Linha do plano inicial</h2><p>Cada semana é liberada conforme a data de início do plano. Atividades extras da Emma aparecem separadamente.</p></div></div>
    <div class="plan-grid">
        <?php for ($week = 1; $week <= 4; $week++): $w = $weekRows[$week] ?? null; $total=(int)($w['total']??0); $done=(int)($w['completed']??0); $pct=$total>0?round(($done/$total)*100):0; $future=$w && $w['available_from'] && strtotime((string)$w['available_from'])>strtotime('today'); ?>
            <article class="plan-week <?= $future ? 'is-future' : '' ?>">
                <span>Semana <?= $week ?></span>
                <strong><?= $done ?>/<?= $total ?> concluídas</strong>
                <div class="progress slim"><span data-progress="<?= $pct ?>"></span></div>
                <small><?= $future ? 'Libera em '.e(ui_date_only((string)$w['available_from'])) : ($total ? 'Em andamento ou concluída' : 'Em preparação') ?></small>
            </article>
        <?php endfor; ?>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div><h2>Atividades</h2><p>A média considera apenas atividades concluídas que receberam nota.</p></div>
        <div class="filter-row" style="margin:0">
            <?php foreach ([''=>'Todas','pending'=>'Pendentes','scheduled'=>'Programadas','completed'=>'Concluídas'] as $value=>$label): ?>
                <a class="btn <?= $status === $value ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="<?= $value === '' ? '/portal/activities.php' : '?status='.urlencode($value) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state"><div class="empty-state-icon"><?= ui_icon('activities') ?></div><h3>Nenhuma atividade neste filtro</h3><p>Quando o plano ou a Emma atribuírem uma prática, ela aparecerá aqui.</p><a class="btn btn-primary btn-sm" href="/portal/practice.php">Praticar com Emma</a></div>
    <?php else: ?>
        <?php foreach ($grouped as $groupKey => $items): ?>
            <div class="activity-group section-gap-sm">
                <div class="panel-head compact"><div><h3><?= $groupKey === 'extra' ? 'Atividades extras' : 'Semana '.(int)str_replace('week_','',$groupKey) ?></h3><p><?= $groupKey === 'extra' ? 'Reforços criados a partir das suas necessidades.' : 'Práticas previstas no plano inicial.' ?></p></div></div>
                <div class="activity-list">
                    <?php foreach ($items as $row): $future=$row['status']==='pending' && $row['available_from'] && strtotime((string)$row['available_from'])>strtotime('today'); ?>
                        <article class="activity-card-row <?= $future ? 'is-disabled' : '' ?>">
                            <div class="activity-card-icon"><?= ui_icon($row['status'] === 'completed' ? 'progress' : ($future ? 'plan' : 'activities')) ?></div>
                            <div class="activity-card-content">
                                <div class="activity-card-title"><div><strong><?= e((string)$row['title']) ?></strong><p><?= e(portal_clean_text($row['description'] ?? '')) ?></p></div><span class="badge <?= $future ? 'neutral' : e(ui_status_class((string)$row['status'])) ?>"><?= $future ? 'Programada' : e(ui_status_label((string)$row['status'])) ?></span></div>
                                <div class="list-meta">
                                    <span class="badge <?= e(ui_level_class((string)$row['level'])) ?>"><?= e((string)($row['level'] ?? '—')) ?></span>
                                    <span class="badge neutral"><?= e(ui_status_label((string)($row['skill'] ?? 'general'))) ?></span>
                                    <span class="badge neutral"><?= (int)$row['estimated_minutes'] ?> min</span>
                                    <?php if ($row['score'] !== null): ?><span class="badge success"><?= number_format((float)$row['score'], 0) ?>%</span><?php endif; ?>
                                    <?php if ($future): ?><span class="badge neutral">Libera <?= e(ui_date_only((string)$row['available_from'])) ?></span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($future): ?><span class="btn btn-secondary btn-sm" aria-disabled="true">Aguardar</span><?php else: ?><a class="btn <?= $row['status'] === 'completed' ? 'btn-secondary' : 'btn-primary' ?> btn-sm" href="/portal/activity.php?id=<?= urlencode((string)$row['id']) ?>"><?= $row['status'] === 'completed' ? 'Ver resultado' : 'Começar' ?></a><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../templates/footer.php'; ?>
