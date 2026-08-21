<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/ui.php';
require_once __DIR__ . '/../src/progress.php';

require_teacher_or_admin();

$q = trim((string)($_GET['q'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$diagnostic = trim((string)($_GET['diagnostic'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$rows = progress_all_student_metrics(true);
$rows = array_values(array_filter($rows, static function(array $row) use ($q,$level,$diagnostic,$status): bool {
    if ($q !== '') {
        $haystack = mb_strtolower(implode(' ', [(string)($row['name']??''),(string)($row['phone']??''),(string)($row['email']??'')]));
        if (!str_contains($haystack, mb_strtolower($q))) return false;
    }
    if ($level !== '' && (string)($row['overall_level']??'') !== $level) return false;
    if ($diagnostic !== '') {
        $d = (string)($row['diagnostic_status']??'pending');
        if ($diagnostic === 'pending' && !in_array($d,['pending','in_progress'],true)) return false;
        if ($diagnostic !== 'pending' && $d !== $diagnostic) return false;
    }
    if ($status !== '' && (string)($row['engagement_status']??'') !== $status) return false;
    return true;
}));

usort($rows, static function(array $a,array $b): int {
    $aTs = !empty($a['last_activity_at']) ? strtotime((string)$a['last_activity_at']) : 0;
    $bTs = !empty($b['last_activity_at']) ? strtotime((string)$b['last_activity_at']) : 0;
    return $bTs <=> $aTs;
});

$pageTitle='Alunos';
$pageSubtitle='Busque, filtre e compare o avanço real de cada aluno.';
require __DIR__.'/../templates/header.php';
?>

<section class="panel">
    <div class="panel-head"><div><h2>Base de alunos</h2><p><?=count($rows)?> resultado(s). As métricas são consolidadas das atividades e interações registradas.</p></div><a class="btn btn-secondary btn-sm" href="/admin/progress.php"><?=ui_icon('progress','icon-sm')?> Visão geral</a></div>
    <form class="search-bar progress-filter" method="get">
        <input name="q" value="<?=e($q)?>" placeholder="Buscar por nome, telefone ou e-mail">
        <select name="level"><option value="">Todos os níveis</option><?php foreach(['PRE-A1','A1','A2','B1','B2','C1','C2'] as $item):?><option value="<?=e($item)?>" <?=$level===$item?'selected':''?>><?=e($item)?></option><?php endforeach;?></select>
        <select name="diagnostic"><option value="">Todos os diagnósticos</option><option value="pending" <?=$diagnostic==='pending'?'selected':''?>>Em aberto</option><option value="completed" <?=$diagnostic==='completed'?'selected':''?>>Concluído</option></select>
        <select name="status"><option value="">Todas as situações</option><option value="active" <?=$status==='active'?'selected':''?>>Ativo</option><option value="attention" <?=$status==='attention'?'selected':''?>>Atenção</option><option value="inactive" <?=$status==='inactive'?'selected':''?>>Inativo</option><option value="not_started" <?=$status==='not_started'?'selected':''?>>Não iniciou</option></select>
        <button class="btn btn-primary">Filtrar</button>
    </form>

    <?php if(!$rows):?><div class="empty-state"><div class="empty-state-icon"><?=ui_icon('students')?></div><h3>Nenhum aluno encontrado</h3><p>Ajuste os filtros para localizar outro cadastro.</p></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Situação</th><th>Nível</th><th>Competências</th><th>Semana</th><th>Atividades</th><th>Vocabulário</th><th>Pendências</th><th>Última atividade</th></tr></thead><tbody>
    <?php foreach($rows as $student):?><tr>
        <td><a class="table-link" href="/student.php?id=<?=e((string)$student['id'])?>"><strong><?=e((string)$student['name'])?></strong><div class="label"><?=e((string)($student['phone']?:$student['email']?:'Sem contato'))?></div></a></td>
        <td><span class="badge <?=e(progress_engagement_class((string)$student['engagement_status']))?>"><?=e(progress_engagement_label((string)$student['engagement_status']))?></span></td>
        <td><span class="badge <?=e(ui_level_class((string)$student['overall_level']))?>"><?=e((string)$student['overall_level'])?></span><div class="label"><?=e(ui_status_label((string)$student['diagnostic_status']))?></div></td>
        <td><strong><?=number_format((float)$student['skill_average'],0)?>%</strong><div class="label"><?= (int)$student['skills_measured']?>/8 medidas</div></td>
        <td><strong><?=number_format((float)$student['week']['goal_percent'],0)?>%</strong><div class="progress slim"><span data-progress="<?= (float)$student['week']['goal_percent']?>"></span></div></td>
        <td><strong><?= (int)$student['activities_completed']?>/<?= (int)$student['activities_total']?></strong><div class="label">média <?=number_format((float)$student['activity_average_score'],0)?>%</div></td>
        <td><strong><?= (int)$student['vocabulary_mastered']?>/<?= (int)$student['vocabulary_total']?></strong><div class="label"><?=number_format((float)$student['vocabulary_mastery_rate'],0)?>% dominado</div></td>
        <td><span class="badge warning"><?= (int)$student['pending_total']?></span></td>
        <td><?=e(ui_relative_date($student['last_activity_at']))?></td>
    </tr><?php endforeach;?>
    </tbody></table></div><?php endif;?>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
