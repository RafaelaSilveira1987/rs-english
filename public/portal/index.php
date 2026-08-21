<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';
require_once __DIR__ . '/../../src/progress.php';

$user = require_student();
$pdo = db();
$studentId = (string)$user['student_id'];
$m = progress_student_metrics($studentId, true);
if (!$m) { http_response_code(404); exit('Perfil do aluno não encontrado.'); }

$plan = portal_active_plan($studentId);
$recentStmt = $pdo->prepare("SELECT role,content,transcription,message_type,created_at FROM messages WHERE student_id=:id ORDER BY created_at DESC LIMIT 6");
$recentStmt->execute(['id'=>$studentId]);
$recentMessages = $recentStmt->fetchAll();

$skillLabels = [
    'grammar'=>'Gramática','vocabulary'=>'Vocabulário','speaking'=>'Fala','listening'=>'Compreensão oral',
    'reading'=>'Leitura','writing'=>'Escrita','fluency'=>'Fluência','pronunciation'=>'Pronúncia'
];

$pageTitle = 'Início';
$pageSubtitle = 'Seu aprendizado e seus números reais, atualizados a partir de cada prática.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="hero">
    <div class="hero-copy">
        <span class="badge dark <?= e(ui_level_class((string)$m['overall_level'])) ?>">Nível <?= e((string)$m['overall_level']) ?></span>
        <h2>Olá, <?= e(ui_first_name((string)$m['name'])) ?>. Seu progresso está sendo registrado.</h2>
        <p class="label"><?= e((string)$m['goal']) ?> · Última atividade: <?= e(ui_relative_date($m['last_activity_at'])) ?></p>
        <div class="hero-actions">
            <?php if (($m['diagnostic_status'] ?? 'pending') !== 'completed'): ?>
                <a class="btn btn-primary" href="/portal/practice.php?mode=diagnostic"><?= ui_icon('diagnostic','icon-sm') ?> Continuar diagnóstico</a>
            <?php else: ?>
                <a class="btn btn-primary" href="/portal/practice.php"><?= ui_icon('practice','icon-sm') ?> Praticar com Emma</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="/portal/progress.php"><?= ui_icon('progress','icon-sm') ?> Ver progresso completo</a>
        </div>
    </div>
    <div class="hero-stat"><strong><?= (int)$m['xp'] ?> XP</strong><span><?= (int)$m['streak_days_real'] ?> dia(s) de sequência real</span></div>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Competências medidas</div><div class="metric"><?= number_format((float)$m['skill_average'],0) ?>%</div><div class="metric-sub"><?= (int)$m['skills_measured'] ?>/8 competências já avaliadas</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Meta desta semana</div><div class="metric"><?= number_format((float)$m['week']['goal_percent'],0) ?>%</div><div class="metric-sub"><?= (int)$m['week']['completed_activities'] ?> atividades · <?= (int)$m['week']['completed_minutes'] ?> min registrados</div></div><div class="metric-icon"><?= ui_icon('target') ?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/portal/activities.php"></a><div><div class="label">Atividades concluídas</div><div class="metric"><?= (int)$m['activities_completed'] ?></div><div class="metric-sub"><?= number_format((float)$m['activity_completion_rate'],0) ?>% das atribuídas · média <?= number_format((float)$m['activity_average_score'],0) ?>%</div></div><div class="metric-icon"><?= ui_icon('activities') ?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/portal/vocabulary.php"></a><div><div class="label">Palavras dominadas</div><div class="metric"><?= (int)$m['vocabulary_mastered'] ?></div><div class="metric-sub"><?= (int)$m['vocabulary_total'] ?> registradas · <?= number_format((float)$m['vocabulary_mastery_rate'],0) ?>% dominadas</div></div><div class="metric-icon"><?= ui_icon('vocabulary') ?></div></article>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Suas competências</h2><p>Notas realmente registradas pelo diagnóstico e avaliações da Emma.</p></div><strong><?= number_format((float)$m['skill_average'],0) ?>%</strong></div>
        <?php foreach ($skillLabels as $key => $label): $score = (float)$m['skills'][$key]; ?>
            <div class="skill"><div class="skill-head"><span><?= e($label) ?></span><strong><?= $score > 0 ? number_format($score,0).'%' : 'Ainda não medida' ?></strong></div><div class="progress"><span data-progress="<?= $score ?>"></span></div></div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Esta semana</h2><p>Progresso calculado pelos registros reais de estudo.</p></div></div>
        <div class="skill"><div class="skill-head"><span>Minutos registrados</span><strong><?= (int)$m['week']['completed_minutes'] ?>/<?= (int)$m['week']['target_minutes'] ?></strong></div><div class="progress"><span data-progress="<?= (float)$m['week']['minutes_pct'] ?>"></span></div></div>
        <div class="skill"><div class="skill-head"><span>Atividades</span><strong><?= (int)$m['week']['completed_activities'] ?>/<?= (int)$m['week']['target_activities'] ?></strong></div><div class="progress"><span data-progress="<?= (float)$m['week']['activities_pct'] ?>"></span></div></div>
        <div class="skill"><div class="skill-head"><span>Palavras novas</span><strong><?= (int)$m['week']['learned_words'] ?>/<?= (int)$m['week']['target_words'] ?></strong></div><div class="progress"><span data-progress="<?= (float)$m['week']['words_pct'] ?>"></span></div></div>
        <div class="list-card"><strong><?= e((string)($plan['goal'] ?? 'Plano em preparação')) ?></strong><p><?= $plan ? 'Meta de nível: '.e((string)$plan['target_level']).' · até '.e(ui_date_only((string)$plan['end_date'])) : 'Conclua o diagnóstico para receber um plano personalizado.' ?></p></div>
    </section>
</div>

<section class="cards cards-4 section-gap">
    <article class="card metric-card"><div><div class="label">Sessões em 30 dias</div><div class="metric"><?= (int)$m['sessions_30d'] ?></div><div class="metric-sub"><?= (int)$m['messages_30d'] ?> mensagens registradas</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
    <article class="card metric-card"><div><div class="label">Revisões pendentes</div><div class="metric"><?= (int)$m['pending_total'] ?></div><div class="metric-sub"><?= (int)$m['corrections_due'] ?> correções + <?= (int)$m['vocabulary_due'] ?> palavras + <?= (int)$m['activities_pending'] ?> atividades</div></div><div class="metric-icon"><?= ui_icon('corrections') ?></div></article>
    <article class="card metric-card"><div><div class="label">Conquistas</div><div class="metric"><?= (int)$m['achievements_total'] ?></div><div class="metric-sub">Marcos desbloqueados</div></div><div class="metric-icon"><?= ui_icon('achievement') ?></div></article>
    <article class="card metric-card"><div><div class="label">Áudio praticado</div><div class="metric"><?= number_format((float)$m['voice_minutes_total'],0) ?></div><div class="metric-sub">minutos de áudio registrados</div></div><div class="metric-icon"><?= ui_icon('practice') ?></div></article>
</section>

<?php if ($recentMessages): ?>
<section class="panel section-gap"><div class="panel-head"><div><h2>Últimas interações</h2><p>Histórico vinculado ao seu próprio cadastro.</p></div></div><div class="timeline">
<?php foreach ($recentMessages as $message): ?><div class="timeline-item"><span class="timeline-dot"></span><strong><?= $message['role']==='teacher'?'Emma':'Você' ?> · <?= e(ui_status_label((string)$message['message_type'])) ?></strong><p><?= e(mb_strimwidth((string)($message['content'] ?: $message['transcription'] ?: ''),0,180,'…')) ?></p><time><?= e(ui_date((string)$message['created_at'])) ?></time></div><?php endforeach; ?>
</div></section>
<?php endif; ?>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
