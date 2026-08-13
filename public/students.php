<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();
$q=trim($_GET['q'] ?? '');

$sql="
SELECT
    s.id,s.name,s.phone,s.email,s.status,
    COALESCE(sp.overall_level,'A1') overall_level,
    COALESCE(sp.xp,0) xp,
    COALESCE(sp.grammar_score,0) grammar_score,
    COALESCE(sp.vocabulary_score,0) vocabulary_score,
    sp.last_study_at,
    (
      SELECT COUNT(*)
      FROM student_errors se
      WHERE se.student_id=s.id
        AND se.status='learning'
        AND (se.next_review_at IS NULL OR se.next_review_at<=NOW())
    ) errors_due,
    (
      SELECT COUNT(*)
      FROM student_vocabulary sv
      WHERE sv.student_id=s.id
        AND sv.status IN ('learning','review')
        AND (sv.next_review_at IS NULL OR sv.next_review_at<=NOW())
    ) vocab_due
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id=s.id
";

$params=[];

if($q!==''){
    $sql.=" WHERE s.name ILIKE :q OR s.phone ILIKE :q OR s.email ILIKE :q";
    $params['q']="%{$q}%";
}

$sql.=" ORDER BY COALESCE(sp.last_study_at,s.created_at) DESC";

$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$rows=$stmt->fetchAll();

$pageTitle='Alunos';
require __DIR__.'/../templates/header.php';
?>

<section class="panel">
<form class="search-bar" method="get">
    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nome, telefone ou e-mail">
    <button class="btn btn-primary" style="max-width:130px">Buscar</button>
</form>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Aluno</th>
    <th>Nível</th>
    <th>Grammar</th>
    <th>Vocabulary</th>
    <th>Revisões</th>
    <th>XP</th>
    <th>Último estudo</th>
</tr>
</thead>
<tbody>
<?php foreach($rows as $student): ?>
<tr>
    <td>
        <a href="/student.php?id=<?= urlencode($student['id']) ?>">
            <strong><?= htmlspecialchars($student['name']) ?></strong>
        </a>
        <div class="label"><?= htmlspecialchars($student['phone'] ?? '-') ?></div>
    </td>
    <td><span class="badge"><?= htmlspecialchars($student['overall_level']) ?></span></td>
    <td><?= number_format((float)$student['grammar_score'],0) ?>%</td>
    <td><?= number_format((float)$student['vocabulary_score'],0) ?>%</td>
    <td>
        <span class="badge warning">
            <?= (int)$student['errors_due']+(int)$student['vocab_due'] ?>
        </span>
    </td>
    <td><?= (int)$student['xp'] ?></td>
    <td>
        <?= $student['last_study_at']
            ? date('d/m/Y H:i',strtotime($student['last_study_at']))
            : '-' ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
