<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$status = trim((string)($_GET['status'] ?? ''));

$sql = <<<'SQL'
    SELECT
        sa.id,
        sa.status,
        sa.assigned_at,
        sa.started_at,
        sa.completed_at,
        sa.score,
        sa.xp_earned,
        sa.attempts,
        a.title,
        a.description,
        a.activity_type,
        a.skill,
        a.level,
        a.xp_reward,
        a.estimated_minutes
    FROM student_activities sa
    JOIN activities a ON a.id = sa.activity_id
    WHERE sa.student_id = :student_id
SQL;
$params = ['student_id' => $user['student_id']];
if (in_array($status, ['pending', 'completed'], true)) {
    $sql .= ' AND sa.status = :status';
    $params['status'] = $status;
}
$sql .= " ORDER BY CASE WHEN sa.status = 'pending' THEN 0 ELSE 1 END, COALESCE(sa.completed_at, sa.assigned_at) DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$allStats = $pdo->prepare(<<<'SQL'
    SELECT
        COUNT(*) FILTER (WHERE status = 'pending') AS pending,
        COUNT(*) FILTER (WHERE status = 'completed') AS completed,
        COALESCE(SUM(xp_earned) FILTER (WHERE status = 'completed'), 0) AS xp,
        COALESCE(AVG(score) FILTER (WHERE status = 'completed' AND score IS NOT NULL), 0) AS average
    FROM student_activities
    WHERE student_id = :student_id
SQL);
$allStats->execute(['student_id' => $user['student_id']]);
$stats = $allStats->fetch() ?: [];

$pageTitle = 'Minhas atividades';
$pageSubtitle = 'Exercícios personalizados a partir do seu plano, erros e vocabulário.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Pendentes</div><div class="metric"><?= (int)($stats['pending'] ?? 0) ?></div><div class="metric-sub">Próximas atividades</div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><div><div class="label">Concluídas</div><div class="metric"><?= (int)($stats['completed'] ?? 0) ?></div><div class="metric-sub">No seu histórico</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Média</div><div class="metric"><?= number_format((float)($stats['average'] ?? 0), 0) ?>%</div><div class="metric-sub">Nas atividades avaliadas</div></div><div class="metric-icon"><?= ui_icon('target') ?></div></article>
    <article class="card metric-card"><div><div class="label">XP conquistado</div><div class="metric"><?= (int)($stats['xp'] ?? 0) ?></div><div class="metric-sub">Recompensas registradas</div></div><div class="metric-icon"><?= ui_icon('sparkles') ?></div></article>
</section>

<section class="panel">
    <div class="panel-head">
        <div><h2>Atividades</h2><p>Priorize as pendentes e acompanhe o feedback das concluídas.</p></div>
        <div class="filter-row" style="margin:0">
            <a class="btn <?= $status === '' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="/portal/activities.php">Todas</a>
            <a class="btn <?= $status === 'pending' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="?status=pending">Pendentes</a>
            <a class="btn <?= $status === 'completed' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="?status=completed">Concluídas</a>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><?= ui_icon('activities') ?></div>
            <h3>Nenhuma atividade neste filtro</h3>
            <p>Quando novas atividades forem atribuídas, elas aparecerão aqui.</p>
            <a class="btn btn-primary btn-sm" href="/portal/practice.php">Praticar com Emma</a>
        </div>
    <?php else: ?>
        <div class="activity-list">
            <?php foreach ($rows as $row): ?>
                <article class="activity-card-row">
                    <div class="activity-card-icon"><?= ui_icon($row['status'] === 'completed' ? 'progress' : 'activities') ?></div>
                    <div class="activity-card-content">
                        <div class="activity-card-title">
                            <div><strong><?= e((string)$row['title']) ?></strong><p><?= e((string)($row['description'] ?? '')) ?></p></div>
                            <span class="badge <?= e(ui_status_class((string)$row['status'])) ?>"><?= e(ui_status_label((string)$row['status'])) ?></span>
                        </div>
                        <div class="list-meta">
                            <span class="badge <?= e(ui_level_class((string)$row['level'])) ?>"><?= e((string)($row['level'] ?? '—')) ?></span>
                            <span class="badge neutral"><?= e(ui_status_label((string)($row['skill'] ?? 'general'))) ?></span>
                            <span class="badge neutral"><?= (int)$row['estimated_minutes'] ?> min</span>
                            <span class="badge neutral"><?= (int)$row['xp_reward'] ?> XP</span>
                            <?php if ($row['score'] !== null): ?><span class="badge success"><?= number_format((float)$row['score'], 0) ?>%</span><?php endif; ?>
                            <?php if ((int)$row['attempts'] > 0): ?><span class="badge neutral"><?= (int)$row['attempts'] ?> tentativa(s)</span><?php endif; ?>
                        </div>
                    </div>
                    <a class="btn <?= $row['status'] === 'completed' ? 'btn-secondary' : 'btn-primary' ?> btn-sm" href="/portal/activity.php?id=<?= urlencode((string)$row['id']) ?>">
                        <?= $row['status'] === 'completed' ? 'Ver resultado' : 'Começar' ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
