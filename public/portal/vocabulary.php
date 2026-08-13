<?php
declare(strict_types=1);

require_once __DIR__.'/../../src/db.php';
require_once __DIR__.'/../../src/auth.php';

$user=require_student();
$pdo=db();

$stmt=$pdo->prepare("
SELECT
    v.word,v.translation,v.definition_en,v.example,v.level,
    sv.status,sv.mastery_score,sv.repetitions,
    sv.correct_answers,sv.incorrect_answers,sv.next_review_at
FROM student_vocabulary sv
JOIN vocabulary v ON v.id=sv.vocabulary_id
WHERE sv.student_id=:id
ORDER BY
    CASE WHEN sv.status='mastered' THEN 1 ELSE 0 END,
    sv.next_review_at NULLS FIRST,
    v.word
");
$stmt->execute(['id'=>$user['student_id']]);
$rows=$stmt->fetchAll();

$pageTitle='Meu vocabulário';
require __DIR__.'/../../templates/header.php';
?>

<section class="panel">
<h2>Palavras acompanhadas</h2>

<div class="table-wrap">
<table>
<thead>
<tr><th>Palavra</th><th>Tradução</th><th>Nível</th><th>Domínio</th><th>Status</th><th>Próxima revisão</th></tr>
</thead>
<tbody>
<?php foreach($rows as $row): ?>
<tr>
    <td>
        <strong><?= htmlspecialchars($row['word']) ?></strong>
        <?php if($row['example']): ?><div class="label"><?= htmlspecialchars($row['example']) ?></div><?php endif; ?>
    </td>
    <td><?= htmlspecialchars($row['translation'] ?? '-') ?></td>
    <td><?= htmlspecialchars($row['level'] ?? '-') ?></td>
    <td><?= number_format((float)$row['mastery_score'],0) ?>%</td>
    <td><span class="badge <?= $row['status']==='mastered'?'success':'' ?>"><?= htmlspecialchars($row['status']) ?></span></td>
    <td><?= $row['next_review_at'] ? date('d/m/Y H:i',strtotime($row['next_review_at'])) : '-' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php require __DIR__.'/../../templates/footer.php'; ?>
