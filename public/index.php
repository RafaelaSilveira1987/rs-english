<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/progress.php';

require_teacher_or_admin();

$rows = progress_all_student_metrics(true);
$summary = progress_admin_summary($rows);

usort($rows, static function(array $a, array $b): int {
    $aTs = !empty($a['last_activity_at']) ? strtotime((string)$a['last_activity_at']) : 0;
    $bTs = !empty($b['last_activity_at']) ? strtotime((string)$b['last_activity_at']) : 0;
    return $bTs <=> $aTs;
});
$recent = array_slice($rows, 0, 10);

$attention = array_values(array_filter($rows, static fn($row) => ($row['attention_reasons'] ?? []) !== []));
$severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($attention, static function(array $a,array $b) use ($severityRank): int {
    $aReason = $a['attention_reasons'][0] ?? ['severity' => 'low'];
    $bReason = $b['attention_reasons'][0] ?? ['severity' => 'low'];
    $rank = ($severityRank[$aReason['severity']] ?? 9) <=> ($severityRank[$bReason['severity']] ?? 9);
    if ($rank !== 0) return $rank;
    $aDays = $a['days_since_activity'] ?? 99999;
    $bDays = $b['days_since_activity'] ?? 99999;
    return $bDays <=> $aDays;
});
$attention = array_slice($attention, 0, 6);

$pageTitle = 'Dashboard';
$pageSubtitle = 'Visão real da evolução dos alunos e dos pontos que precisam de acompanhamento.';
require __DIR__ . '/../templates/header.php';
?>

<section class="hero">
    <div class="hero-copy">
        <span class="badge dark">RS English Intelligence</span>
        <h2>O painel agora acompanha o que realmente acontece com cada aluno.</h2>
        <p class="label">Diagnóstico, competências, frequência, atividades, vocabulário e revisões são consolidados a partir dos registros reais da plataforma.</p>
        <div class="hero-actions"><a class="btn btn-primary" href="/students.php"><?=ui_icon('students','icon-sm')?> Ver alunos</a><a class="btn btn-secondary" href="/admin/progress.php"><?=ui_icon('progress','icon-sm')?> Progresso geral</a></div>
    </div>
    <div class="hero-stat"><strong><?= number_format((float)$summary['active_7d_percent'],0) ?>%</strong><span>da base estudou nos últimos 7 dias</span></div>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><a class="metric-link" href="/students.php"></a><div><div class="label">Alunos cadastrados</div><div class="metric"><?= (int)$summary['students_total'] ?></div><div class="metric-sub"><?= (int)$summary['active_7d'] ?> em 7 dias · <?= (int)$summary['active_30d'] ?> em 30 dias</div></div><div class="metric-icon"><?=ui_icon('students')?></div></article>
    <article class="card metric-card"><div><div class="label">Média de competências</div><div class="metric"><?= number_format((float)$summary['skill_average'],0) ?>%</div><div class="metric-sub">Somente habilidades efetivamente avaliadas</div></div><div class="metric-icon"><?=ui_icon('progress')?></div></article>
    <article class="card metric-card"><div><div class="label">Meta semanal média</div><div class="metric"><?= number_format((float)$summary['weekly_goal_average'],0) ?>%</div><div class="metric-sub">Progresso atual da base</div></div><div class="metric-icon"><?=ui_icon('target')?></div></article>
    <article class="card metric-card"><a class="metric-link" href="/admin/progress.php?attention=1"></a><div><div class="label">Precisam de atenção</div><div class="metric"><?= (int)$summary['needs_attention'] ?></div><div class="metric-sub"><?= (int)$summary['attention_high'] ?> prioritários · <?= (int)$summary['recurring_errors'] ?> erros recorrentes</div></div><div class="metric-icon"><?=ui_icon('health')?></div></article>
</section>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Diagnóstico concluído</div><div class="metric"><?= number_format((float)$summary['diagnostic_completion_percent'],0) ?>%</div><div class="metric-sub"><?= (int)$summary['diagnostic_completed'] ?> aluno(s) classificados</div></div><div class="metric-icon"><?=ui_icon('diagnostic')?></div></article>
    <article class="card metric-card"><div><div class="label">Conclusão de atividades</div><div class="metric"><?= number_format((float)$summary['activity_completion_percent'],0) ?>%</div><div class="metric-sub">Taxa real das atividades atribuídas</div></div><div class="metric-icon"><?=ui_icon('activities')?></div></article>
    <article class="card metric-card"><div><div class="label">Sessões em 7 dias</div><div class="metric"><?= (int)$summary['sessions_7d'] ?></div><div class="metric-sub">Conversações e avaliações</div></div><div class="metric-icon"><?=ui_icon('practice')?></div></article>
    <article class="card metric-card"><div><div class="label">Tempo de estudo</div><div class="metric"><?=number_format(((int)$summary['study_minutes_total'])/60,1,',','.')?>h</div><div class="metric-sub"><?= (int)$summary['words_mastered'] ?> palavras dominadas na base</div></div><div class="metric-icon"><?=ui_icon('history')?></div></article>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Alunos mais recentes</h2><p>Última atividade registrada em qualquer canal.</p></div><a class="btn btn-secondary btn-sm" href="/students.php">Ver todos <?=ui_icon('arrow','icon-sm')?></a></div>
        <?php if(!$recent):?><div class="empty-state"><h3>Nenhum aluno cadastrado</h3><p>Os dados aparecerão assim que os alunos iniciarem o uso.</p></div><?php else:?><div class="stack">
            <?php foreach($recent as $student):?><a class="list-card progress-student-card" href="/student.php?id=<?=e((string)$student['id'])?>"><div class="list-row"><div class="list-main"><strong><?=e((string)$student['name'])?></strong><p><?=e((string)($student['phone']?:$student['email']?:'Sem contato'))?></p></div><span class="badge <?=e(progress_engagement_class((string)$student['engagement_status']))?>"><?=e(progress_engagement_label((string)$student['engagement_status']))?></span></div><div class="progress-card-stats"><span><b><?=e((string)$student['overall_level'])?></b> nível</span><span><b><?=number_format((float)$student['skill_average'],0)?>%</b> competências</span><span><b><?=number_format((float)$student['week']['goal_percent'],0)?>%</b> semana</span><span><b><?= (int)$student['sessions_30d']?></b> sessões 30d</span></div><small>Última atividade <?=e(ui_relative_date($student['last_activity_at']))?></small></a><?php endforeach; ?>
        </div><?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Alunos para acompanhar</h2><p>Prioridade baseada em frequência, diagnóstico, pendências e erros recorrentes.</p></div><a class="btn btn-secondary btn-sm" href="/admin/progress.php?attention=1">Analisar base</a></div>
        <?php if(!$attention):?><div class="empty-state compact"><h3>Nenhum alerta de frequência</h3><p>Todos os alunos estão com atividade recente.</p></div><?php else:?><div class="stack">
            <?php foreach($attention as $student):$reason=$student['attention_reasons'][0]??null;?><a class="list-card" href="/student.php?id=<?=e((string)$student['id'])?>"><div class="list-row"><div class="list-main"><strong><?=e((string)$student['name'])?></strong><p><?=e((string)($reason['label']??'Revisar acompanhamento'))?></p></div><span class="badge <?=e(progress_engagement_class((string)$student['engagement_status']))?>"><?=e((string)$student['overall_level'])?></span></div><div class="list-meta"><span><?=number_format((float)$student['week']['goal_percent'],0)?>% da meta</span><span><?= (int)$student['study_minutes_30d']?> min em 30d</span><span><?= (int)$student['corrections_recurring']?> erro(s) recorrente(s)</span></div></a><?php endforeach;?>
        </div><?php endif;?>
    </section>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
