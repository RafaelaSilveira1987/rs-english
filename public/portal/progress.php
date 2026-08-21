<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';
require_once __DIR__ . '/../../src/progress.php';

$user = require_student();
$studentId = (string)$user['student_id'];
$m = progress_student_metrics($studentId, true);
if (!$m) { http_response_code(404); exit('Perfil do aluno não encontrado.'); }

$plan = portal_active_plan($studentId);
$days = progress_student_daily_activity($studentId, 14);
$history = progress_snapshot_history($studentId, 30);

$reportsStmt = db()->prepare("SELECT week_start,week_end,teacher_summary,report_data,created_at FROM weekly_reports WHERE student_id=:id ORDER BY week_start DESC LIMIT 8");
$reportsStmt->execute(['id'=>$studentId]);
$reports = $reportsStmt->fetchAll();

$achievementsStmt = db()->prepare("SELECT a.code,a.title,a.description,a.xp_reward,sa.earned_at FROM student_achievements sa JOIN achievements a ON a.id=sa.achievement_id WHERE sa.student_id=:id ORDER BY sa.earned_at DESC LIMIT 12");
$achievementsStmt->execute(['id'=>$studentId]);
$achievements = $achievementsStmt->fetchAll();

$maxEvents = max(1, ...array_map(static fn($d)=>(int)$d['sessions']+(int)$d['activities']+(int)$d['messages'], $days));
$recommendation = $m['recommendation'] ?? [];

$skillLabels = [
    'grammar'=>'Gramática','vocabulary'=>'Vocabulário','speaking'=>'Fala','listening'=>'Compreensão oral',
    'reading'=>'Leitura','writing'=>'Escrita','fluency'=>'Fluência','pronunciation'=>'Pronúncia'
];

$skillTrend = null;
if (count($history) >= 2) {
    $first = (float)$history[0]['skill_average'];
    $last = (float)$history[count($history)-1]['skill_average'];
    $skillTrend = round($last - $first, 1);
}

$pageTitle = 'Meu progresso';
$pageSubtitle = 'Acompanhe seu avanço com dados reais de atividades, conversas, vocabulário e avaliações.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="hero progress-hero">
    <div class="hero-copy">
        <span class="badge dark <?= e(ui_level_class((string)$m['overall_level'])) ?>">Nível <?= e((string)$m['overall_level']) ?></span>
        <h2><?= e(ui_first_name((string)$m['name'])) ?>, este é o retrato atual da sua evolução.</h2>
        <p class="label">Meta de nível: <?= e((string)($plan['target_level'] ?? 'A definir')) ?> · última atividade <?= e(ui_relative_date($m['last_activity_at'])) ?>.</p>
        <div class="hero-actions"><a class="btn btn-primary" href="/portal/practice.php"><?= ui_icon('practice','icon-sm') ?> Praticar agora</a><a class="btn btn-secondary" href="/portal/activities.php"><?= ui_icon('activities','icon-sm') ?> Fazer atividades</a></div>
    </div>
    <div class="hero-stat"><strong><?= number_format((float)$m['skill_average'],0) ?>%</strong><span>média das <?= (int)$m['skills_measured'] ?> competências já medidas</span></div>
</section>

<?php if ($recommendation): ?>
<section class="recommendation-card priority-<?= e((string)($recommendation['priority'] ?? 'medium')) ?> section-gap-sm">
    <div class="recommendation-icon"><?= ui_icon('teacher') ?></div>
    <div class="recommendation-copy"><span>Próximo passo recomendado</span><h2><?= e((string)($recommendation['title'] ?? 'Continue praticando')) ?></h2><p><?= e((string)($recommendation['description'] ?? 'Uma prática curta ajuda a manter sua evolução.')) ?></p></div>
    <a class="btn btn-primary btn-sm" href="<?= e((string)($recommendation['action_url'] ?? '/portal/practice.php')) ?>"><?= e((string)($recommendation['action_label'] ?? 'Começar prática')) ?></a>
</section>
<?php endif; ?>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Sequência real</div><div class="metric"><?= (int)$m['streak_days_real'] ?></div><div class="metric-sub">dias consecutivos com atividade</div></div><div class="metric-icon"><?= ui_icon('streak') ?></div></article>
    <article class="card metric-card"><div><div class="label">Meta semanal</div><div class="metric"><?= number_format((float)$m['week']['goal_percent'],0) ?>%</div><div class="metric-sub"><?= (int)$m['week']['completed_minutes'] ?> min · <?= (int)$m['week']['completed_activities'] ?> atividades</div></div><div class="metric-icon"><?= ui_icon('target') ?></div></article>
    <article class="card metric-card"><div><div class="label">Média das atividades</div><div class="metric"><?= number_format((float)$m['activity_average_score'],0) ?>%</div><div class="metric-sub"><?= (int)$m['activities_completed'] ?> concluídas de <?= (int)$m['activities_total'] ?></div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><div><div class="label">Vocabulário dominado</div><div class="metric"><?= number_format((float)$m['vocabulary_mastery_rate'],0) ?>%</div><div class="metric-sub"><?= (int)$m['vocabulary_mastered'] ?> de <?= (int)$m['vocabulary_total'] ?> palavras</div></div><div class="metric-icon"><?= ui_icon('vocabulary') ?></div></article>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Atividade nos últimos 14 dias</h2><p>Cada barra combina mensagens do aluno, sessões e atividades concluídas.</p></div></div>
        <div class="weekly-chart admin-activity-chart">
            <?php foreach ($days as $day): $events=(int)$day['sessions']+(int)$day['activities']+(int)$day['messages']; $height=max(7,(int)round(($events/$maxEvents)*100)); ?>
                <div class="weekly-bar-column" title="<?= (int)$day['messages'] ?> mensagens, <?= (int)$day['sessions'] ?> sessões, <?= (int)$day['activities'] ?> atividades">
                    <span class="weekly-value"><?= $events ?></span><div class="weekly-bar-track"><span style="height:<?= $height ?>%"></span></div><strong><?= e((new DateTimeImmutable((string)$day['day']))->format('d/m')) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Meta desta semana</h2><p><?= e(ui_date_only((string)$m['week']['week_start'])) ?> a <?= e(ui_date_only((string)$m['week']['week_end'])) ?></p></div></div>
        <div class="skill"><div class="skill-head"><span>Minutos registrados</span><strong><?= (int)$m['week']['completed_minutes'] ?>/<?= (int)$m['week']['target_minutes'] ?></strong></div><div class="progress"><span data-progress="<?= (float)$m['week']['minutes_pct'] ?>"></span></div></div>
        <div class="skill"><div class="skill-head"><span>Atividades concluídas</span><strong><?= (int)$m['week']['completed_activities'] ?>/<?= (int)$m['week']['target_activities'] ?></strong></div><div class="progress"><span data-progress="<?= (float)$m['week']['activities_pct'] ?>"></span></div></div>
        <div class="skill"><div class="skill-head"><span>Palavras registradas</span><strong><?= (int)$m['week']['learned_words'] ?>/<?= (int)$m['week']['target_words'] ?></strong></div><div class="progress"><span data-progress="<?= (float)$m['week']['words_pct'] ?>"></span></div></div>
        <div class="goal-summary <?= (float)$m['week']['goal_percent'] >= 100 ? 'complete' : '' ?>"><?= ui_icon('target','icon-sm') ?><span><?= (float)$m['week']['goal_percent'] >= 100 ? 'Meta semanal concluída.' : 'Progresso real da semana: '.number_format((float)$m['week']['goal_percent'],0).'%. Continue em pequenas sessões frequentes.' ?></span></div>
    </section>
</div>

<section class="panel section-gap">
    <div class="panel-head"><div><h2>Competências atuais</h2><p>Campos ainda não avaliados aparecem como “Ainda não medida”, em vez de serem tratados como zero.</p></div><?php if ($skillTrend !== null): ?><span class="badge <?= $skillTrend >= 0 ? 'success':'warning' ?>"><?= $skillTrend >= 0 ? '+':'' ?><?= number_format($skillTrend,1) ?> pts desde o primeiro snapshot</span><?php endif; ?></div>
    <div class="skills-grid">
        <?php foreach ($skillLabels as $key=>$label):
            $score=(float)$m['skills'][$key];
            $evidence=$m['skill_evidence'][$key] ?? [];
            $isMeasured=(int)($evidence['evidence_count'] ?? 0) > 0;
        ?>
            <div class="skill-card"><div class="skill-card-score"><strong><?= $isMeasured ? number_format($score,0) : '—' ?></strong><span><?= $isMeasured ? '%' : '' ?></span></div><div><h3><?= e($label) ?></h3><div class="progress"><span data-progress="<?= $isMeasured ? $score : 0 ?>"></span></div><small><?= $isMeasured ? (int)$evidence['evidence_count'].' evidência(s) · '.e(ui_relative_date($evidence['last_observed_at'] ?? null)) : 'Ainda não medida' ?></small></div></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cards cards-4 section-gap">
    <article class="card metric-card"><div><div class="label">Tempo de estudo total</div><div class="metric"><?= (int)$m['study_minutes_total'] ?></div><div class="metric-sub"><?= (int)$m['study_minutes_30d'] ?> min nos últimos 30 dias</div></div><div class="metric-icon"><?= ui_icon('history') ?></div></article>
    <article class="card metric-card"><div><div class="label">Dias ativos em 30 dias</div><div class="metric"><?= (int)$m['active_days_30d'] ?></div><div class="metric-sub"><?= (int)$m['learning_events_30d'] ?> eventos de aprendizagem</div></div><div class="metric-icon"><?= ui_icon('streak') ?></div></article>
    <article class="card metric-card"><div><div class="label">Evidências pedagógicas</div><div class="metric"><?= (int)$m['skill_evidence_count'] ?></div><div class="metric-sub">medições usadas para calcular suas competências</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Erros recorrentes</div><div class="metric"><?= (int)$m['corrections_recurring'] ?></div><div class="metric-sub"><?= number_format((float)$m['corrections_resolved_rate'],0) ?>% das correções resolvidas</div></div><div class="metric-icon"><?= ui_icon('corrections') ?></div></article>
</section>

<section class="cards cards-4 section-gap">
    <article class="card metric-card"><div><div class="label">Sessões totais</div><div class="metric"><?= (int)$m['sessions_total'] ?></div><div class="metric-sub"><?= (int)$m['sessions_30d'] ?> nos últimos 30 dias</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
    <article class="card metric-card"><div><div class="label">Mensagens</div><div class="metric"><?= (int)$m['messages_total'] ?></div><div class="metric-sub"><?= (int)$m['messages_30d'] ?> nos últimos 30 dias</div></div><div class="metric-icon"><?= ui_icon('history') ?></div></article>
    <article class="card metric-card"><div><div class="label">Correções em aberto</div><div class="metric"><?= (int)$m['corrections_open'] ?></div><div class="metric-sub"><?= number_format((float)$m['corrections_resolved_rate'],0) ?>% dos pontos registrados já saíram de “learning”</div></div><div class="metric-icon"><?= ui_icon('corrections') ?></div></article>
    <article class="card metric-card"><div><div class="label">Áudio praticado</div><div class="metric"><?= number_format((float)$m['voice_minutes_total'],0) ?></div><div class="metric-sub">minutos de áudio registrados</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
</section>

<div class="grid-2 section-gap">
    <section class="panel"><div class="panel-head"><div><h2>Conquistas</h2><p>Marcos já liberados.</p></div></div><?php if(!$achievements):?><div class="empty-state compact"><p>As primeiras conquistas aparecerão conforme as práticas forem registradas.</p></div><?php else:?><div class="achievement-grid"><?php foreach($achievements as $achievement):?><article class="achievement-card"><div class="achievement-icon"><?=ui_icon('achievement')?></div><div><strong><?=e((string)$achievement['title'])?></strong><p><?=e((string)($achievement['description']??''))?></p><small>+<?= (int)$achievement['xp_reward']?> XP · <?=e(ui_date((string)$achievement['earned_at']))?></small></div></article><?php endforeach;?></div><?php endif;?></section>
    <section class="panel"><div class="panel-head"><div><h2>Relatórios semanais</h2><p>Resumos pedagógicos salvos pela plataforma.</p></div></div><?php if(!$reports):?><div class="empty-state compact"><p>O primeiro relatório aparecerá quando houver uma semana com dados suficientes.</p></div><?php else:?><div class="stack"><?php foreach($reports as $report):?><details class="report-card"><summary><span><strong><?=e(ui_date_only((string)$report['week_start']))?> a <?=e(ui_date_only((string)$report['week_end']))?></strong><small>Gerado em <?=e(ui_date((string)$report['created_at']))?></small></span><?=ui_icon('arrow','icon-sm')?></summary><div class="report-card-body"><p><?=nl2br(e((string)($report['teacher_summary']?:'Resumo ainda não preenchido.')))?></p></div></details><?php endforeach;?></div><?php endif;?></section>
</div>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
