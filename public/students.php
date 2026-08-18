<?php
declare(strict_types=1);
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';
require_once __DIR__.'/../src/ui.php';
require_teacher_or_admin();
$pdo=db();
$q=trim($_GET['q'] ?? '');
$level=trim($_GET['level'] ?? '');
$diagnostic=trim($_GET['diagnostic'] ?? '');

$sql="
SELECT s.id,s.name,s.phone,s.email,s.status,
 COALESCE(sp.overall_level,'PRE-A1') overall_level,
 COALESCE(sp.diagnostic_status,'pending') diagnostic_status,
 COALESCE(sp.xp,0) xp,COALESCE(sp.grammar_score,0) grammar_score,
 COALESCE(sp.vocabulary_score,0) vocabulary_score,sp.last_study_at,
 (SELECT COUNT(*) FROM student_errors se WHERE se.student_id=s.id AND se.status='learning' AND (se.next_review_at IS NULL OR se.next_review_at<=NOW())) errors_due,
 (SELECT COUNT(*) FROM student_vocabulary sv WHERE sv.student_id=s.id AND sv.status IN ('learning','review') AND (sv.next_review_at IS NULL OR sv.next_review_at<=NOW())) vocab_due,
 (SELECT COUNT(*) FROM student_activities sa WHERE sa.student_id=s.id AND sa.status='pending') activities_due,
 (SELECT COUNT(*) FROM sessions sx WHERE sx.student_id=s.id AND sx.created_at>=NOW()-INTERVAL '30 days') sessions_30d
FROM students s LEFT JOIN student_profiles sp ON sp.student_id=s.id";
$where=[];$params=[];
if($q!==''){$where[]="(s.name ILIKE :q OR COALESCE(s.phone,'') ILIKE :q OR COALESCE(s.email,'') ILIKE :q)";$params['q']="%{$q}%";}
if($level!==''){$where[]="COALESCE(sp.overall_level,'PRE-A1')=:level";$params['level']=$level;}
if($diagnostic!==''){
    if($diagnostic==='pending'){$where[]="COALESCE(sp.diagnostic_status,'pending') IN ('pending','in_progress')";}
    else{$where[]="COALESCE(sp.diagnostic_status,'pending')=:diagnostic";$params['diagnostic']=$diagnostic;}
}
if($where)$sql.=' WHERE '.implode(' AND ',$where);
$sql.=" ORDER BY COALESCE(sp.last_study_at,s.created_at) DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();

$pageTitle='Alunos';$pageSubtitle='Busque, filtre e acompanhe o progresso individual.';require __DIR__.'/../templates/header.php';
?>
<section class="panel">
    <div class="panel-head"><div><h2>Base de alunos</h2><p><?= count($rows) ?> resultado(s) encontrado(s).</p></div></div>
    <form class="search-bar" method="get">
        <input name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, telefone ou e-mail">
        <select name="level"><option value="">Todos os níveis</option><?php foreach(['PRE-A1','A1','A2','B1','B2','C1','C2'] as $item): ?><option value="<?= e($item) ?>" <?= $level===$item?'selected':'' ?>><?= e($item) ?></option><?php endforeach; ?></select>
        <select name="diagnostic"><option value="">Todos os diagnósticos</option><option value="pending" <?= $diagnostic==='pending'?'selected':'' ?>>Em aberto</option><option value="completed" <?= $diagnostic==='completed'?'selected':'' ?>>Concluído</option></select>
        <button class="btn btn-primary">Filtrar</button>
    </form>

    <?php if(!$rows): ?><div class="empty-state"><div class="empty-state-icon"><?= ui_icon('students') ?></div><h3>Nenhum aluno encontrado</h3><p>Ajuste os filtros para localizar outro cadastro.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Nível</th><th>Diagnóstico</th><th>Desempenho</th><th>Pendências</th><th>Sessões 30d</th><th>XP</th><th>Último estudo</th></tr></thead><tbody>
    <?php foreach($rows as $student): ?>
    <tr>
        <td><a class="table-link" href="/student.php?id=<?= e($student['id']) ?>"><strong><?= e($student['name']) ?></strong><div class="label"><?= e($student['phone'] ?: $student['email'] ?: 'Sem contato') ?></div></a></td>
        <td><span class="badge <?= e(ui_level_class($student['overall_level'])) ?>"><?= e($student['overall_level']) ?></span></td>
        <td><span class="badge <?= e(ui_status_class($student['diagnostic_status'])) ?>"><?= e(ui_status_label($student['diagnostic_status'])) ?></span></td>
        <td><div class="label">G <?= number_format((float)$student['grammar_score'],0) ?>% · V <?= number_format((float)$student['vocabulary_score'],0) ?>%</div></td>
        <td><span class="badge warning"><?= (int)$student['errors_due']+(int)$student['vocab_due']+(int)$student['activities_due'] ?></span></td>
        <td><?= (int)$student['sessions_30d'] ?></td><td><strong><?= (int)$student['xp'] ?></strong></td><td><?= e(ui_relative_date($student['last_study_at'])) ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php require __DIR__.'/../templates/footer.php'; ?>
