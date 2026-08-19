<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$studentId = (string)$user['student_id'];
$profile = portal_profile($studentId);
$plan = portal_active_plan($studentId);
[$weekStart, $weekEnd] = portal_week_bounds();

$weekStmt = $pdo->prepare(<<<'SQL'
    SELECT *
    FROM weekly_goals
    WHERE student_id = :student_id
      AND week_start = :week_start
    LIMIT 1
SQL);
$weekStmt->execute([
    'student_id' => $studentId,
    'week_start' => $weekStart->format('Y-m-d'),
]);
$week = $weekStmt->fetch() ?: [
    'target_minutes' => 100,
    'target_activities' => 4,
    'target_words' => 20,
    'completed_minutes' => 0,
    'completed_activities' => 0,
    'learned_words' => 0,
];

$dailyStmt = $pdo->prepare(<<<'SQL'
    SELECT day::date AS day, SUM(minutes) AS minutes, SUM(events) AS events
    FROM (
        SELECT created_at::date AS day, 0::integer AS minutes, 1::integer AS events
        FROM messages
        WHERE student_id = :student_id AND created_at >= :start_date
        UNION ALL
        SELECT COALESCE(completed_at, assigned_at)::date AS day,
               CASE WHEN status = 'completed' THEN COALESCE(a.estimated_minutes, 0) ELSE 0 END AS minutes,
               CASE WHEN status = 'completed' THEN 1 ELSE 0 END AS events
        FROM student_activities sa
        JOIN activities a ON a.id = sa.activity_id
        WHERE sa.student_id = :student_id AND COALESCE(sa.completed_at, sa.assigned_at) >= :start_date
    ) activity
    GROUP BY day
    ORDER BY day
SQL);
$dailyStmt->execute([
    'student_id' => $studentId,
    'start_date' => (new DateTimeImmutable('-6 days'))->format('Y-m-d 00:00:00'),
]);
$dailyRows = $dailyStmt->fetchAll();
$dailyMap = [];
foreach ($dailyRows as $row) $dailyMap[$row['day']] = $row;

$days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = (new DateTimeImmutable("-{$i} days"));
    $key = $date->format('Y-m-d');
    $days[] = [
        'date' => $key,
        'label' => mb_strtoupper(substr(['dom','seg','ter','qua','qui','sex','sáb'][(int)$date->format('w')], 0, 3)),
        'minutes' => (int)($dailyMap[$key]['minutes'] ?? 0),
        'events' => (int)($dailyMap[$key]['events'] ?? 0),
    ];
}
$maxMinutes = max(1, ...array_column($days, 'minutes'));

$reportsStmt = $pdo->prepare(<<<'SQL'
    SELECT week_start, week_end, teacher_summary, report_data, created_at
    FROM weekly_reports
    WHERE student_id = :student_id
    ORDER BY week_start DESC
    LIMIT 8
SQL);
$reportsStmt->execute(['student_id' => $studentId]);
$reports = $reportsStmt->fetchAll();

$achievementsStmt = $pdo->prepare(<<<'SQL'
    SELECT a.code, a.title, a.description, a.xp_reward, sa.earned_at
    FROM student_achievements sa
    JOIN achievements a ON a.id = sa.achievement_id
    WHERE sa.student_id = :student_id
    ORDER BY sa.earned_at DESC
    LIMIT 12
SQL);
$achievementsStmt->execute(['student_id' => $studentId]);
$achievements = $achievementsStmt->fetchAll();

$skills = [
    'Gramática' => (float)($profile['grammar_score'] ?? 0),
    'Vocabulário' => (float)($profile['vocabulary_score'] ?? 0),
    'Fala' => (float)($profile['speaking_score'] ?? 0),
    'Compreensão oral' => (float)($profile['listening_score'] ?? 0),
    'Leitura' => (float)($profile['reading_score'] ?? 0),
    'Escrita' => (float)($profile['writing_score'] ?? 0),
    'Fluência' => (float)($profile['fluency_score'] ?? 0),
    'Pronúncia' => (float)($profile['pronunciation_score'] ?? 0),
];

$minutesPct = min(100, ((int)$week['completed_minutes'] / max(1, (int)$week['target_minutes'])) * 100);
$activitiesPct = min(100, ((int)$week['completed_activities'] / max(1, (int)$week['target_activities'])) * 100);
$wordsPct = min(100, ((int)$week['learned_words'] / max(1, (int)$week['target_words'])) * 100);
$levelProgress = portal_level_progress((string)($profile['overall_level'] ?? 'PRE-A1'));

$pageTitle = 'Meu progresso';
$pageSubtitle = 'Acompanhe consistência, competências, conquistas e relatórios semanais.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="hero progress-hero">
    <div class="hero-copy">
        <span class="badge dark <?= e(ui_level_class((string)$profile['overall_level'])) ?>">Nível <?= e((string)$profile['overall_level']) ?></span>
        <h2><?= e(ui_first_name((string)$profile['name'])) ?>, cada prática está construindo seu próximo nível.</h2>
        <p class="label">Meta atual: <?= e((string)($plan['target_level'] ?? 'A definir')) ?> · <?= (int)$profile['streak_days'] ?> dias de sequência.</p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="/portal/practice.php"><?= ui_icon('practice', 'icon-sm') ?> Praticar agora</a>
            <a class="btn btn-secondary" href="/portal/activities.php"><?= ui_icon('activities', 'icon-sm') ?> Fazer atividades</a>
        </div>
    </div>
    <div class="level-roadmap">
        <div class="level-roadmap-head"><span>Jornada QECR</span><strong><?= e((string)$profile['overall_level']) ?></strong></div>
        <div class="progress progress-lg"><span data-progress="<?= $levelProgress ?>"></span></div>
        <div class="level-roadmap-labels"><span>PRE-A1</span><span>A1</span><span>A2</span><span>B1</span><span>B2</span><span>C1</span><span>C2</span></div>
    </div>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">XP total</div><div class="metric"><?= (int)$profile['xp'] ?></div><div class="metric-sub">Experiência acumulada</div></div><div class="metric-icon"><?= ui_icon('sparkles') ?></div></article>
    <article class="card metric-card"><div><div class="label">Sequência</div><div class="metric"><?= (int)$profile['streak_days'] ?></div><div class="metric-sub">Dias consecutivos</div></div><div class="metric-icon"><?= ui_icon('streak') ?></div></article>
    <article class="card metric-card"><div><div class="label">Minutos na semana</div><div class="metric"><?= (int)$week['completed_minutes'] ?></div><div class="metric-sub">Meta: <?= (int)$week['target_minutes'] ?></div></div><div class="metric-icon"><?= ui_icon('plan') ?></div></article>
    <article class="card metric-card"><div><div class="label">Conquistas</div><div class="metric"><?= count($achievements) ?></div><div class="metric-sub">Marcos desbloqueados</div></div><div class="metric-icon"><?= ui_icon('achievement') ?></div></article>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Últimos 7 dias</h2><p>Tempo estimado dedicado às atividades.</p></div></div>
        <div class="weekly-chart" aria-label="Gráfico de minutos estudados nos últimos sete dias">
            <?php foreach ($days as $day):
                $height = max(8, (int)round(($day['minutes'] / $maxMinutes) * 100));
            ?>
                <div class="weekly-bar-column">
                    <span class="weekly-value"><?= $day['minutes'] ?> min</span>
                    <div class="weekly-bar-track"><span style="height:<?= $height ?>%"></span></div>
                    <strong><?= e($day['label']) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Meta desta semana</h2><p><?= e($weekStart->format('d/m')) ?> a <?= e($weekEnd->format('d/m')) ?></p></div></div>
        <div class="skill">
            <div class="skill-head"><span>Minutos de estudo</span><strong><?= (int)$week['completed_minutes'] ?>/<?= (int)$week['target_minutes'] ?></strong></div>
            <div class="progress"><span data-progress="<?= $minutesPct ?>"></span></div>
        </div>
        <div class="skill">
            <div class="skill-head"><span>Atividades concluídas</span><strong><?= (int)$week['completed_activities'] ?>/<?= (int)$week['target_activities'] ?></strong></div>
            <div class="progress"><span data-progress="<?= $activitiesPct ?>"></span></div>
        </div>
        <div class="skill">
            <div class="skill-head"><span>Palavras aprendidas</span><strong><?= (int)$week['learned_words'] ?>/<?= (int)$week['target_words'] ?></strong></div>
            <div class="progress"><span data-progress="<?= $wordsPct ?>"></span></div>
        </div>
        <div class="goal-summary <?= $minutesPct >= 100 && $activitiesPct >= 100 ? 'complete' : '' ?>">
            <?= ui_icon('target', 'icon-sm') ?>
            <span><?= $minutesPct >= 100 && $activitiesPct >= 100 ? 'Meta principal concluída. Excelente consistência!' : 'Mantenha práticas curtas e frequentes para completar a meta.' ?></span>
        </div>
    </section>
</div>

<section class="panel section-gap">
    <div class="panel-head"><div><h2>Competências</h2><p>Indicadores atualizados pelas interações, atividades e avaliações.</p></div></div>
    <div class="skills-grid">
        <?php foreach ($skills as $label => $score): ?>
            <div class="skill-card">
                <div class="skill-card-score"><strong><?= number_format($score, 0) ?></strong><span>%</span></div>
                <div><h3><?= e($label) ?></h3><div class="progress"><span data-progress="<?= $score ?>"></span></div></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Conquistas</h2><p>Marcos alcançados na sua jornada.</p></div></div>
        <?php if (!$achievements): ?>
            <div class="empty-state compact"><div class="empty-state-icon"><?= ui_icon('achievement') ?></div><p>As primeiras conquistas serão liberadas com sua prática.</p></div>
        <?php else: ?>
            <div class="achievement-grid">
                <?php foreach ($achievements as $achievement): ?>
                    <article class="achievement-card">
                        <div class="achievement-icon"><?= ui_icon('achievement') ?></div>
                        <div><strong><?= e((string)$achievement['title']) ?></strong><p><?= e((string)($achievement['description'] ?? '')) ?></p><small>+<?= (int)$achievement['xp_reward'] ?> XP · <?= e(ui_date((string)$achievement['earned_at'])) ?></small></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Relatórios semanais</h2><p>Resumo pedagógico produzido a partir do seu desempenho.</p></div></div>
        <?php if (!$reports): ?>
            <div class="empty-state compact"><div class="empty-state-icon"><?= ui_icon('reports') ?></div><p>O primeiro relatório será gerado depois de uma semana com atividades.</p></div>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($reports as $report): ?>
                    <details class="report-card">
                        <summary><span><strong><?= e(ui_date_only((string)$report['week_start'])) ?> a <?= e(ui_date_only((string)$report['week_end'])) ?></strong><small>Gerado em <?= e(ui_date((string)$report['created_at'])) ?></small></span><?= ui_icon('arrow', 'icon-sm') ?></summary>
                        <div class="report-card-body"><p><?= nl2br(e((string)($report['teacher_summary'] ?: 'Resumo ainda não preenchido.'))) ?></p></div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
