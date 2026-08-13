<?php
declare(strict_types=1);

require_once __DIR__.'/../../src/db.php';
require_once __DIR__.'/../../src/auth.php';

$user=require_student();
$pdo=db();

$stmt=$pdo->prepare("
SELECT
    sa.id,sa.status,sa.assigned_at,sa.completed_at,sa.score,sa.xp_earned,
    a.title,a.description,a.activity_type,a.skill,a.level,a.xp_reward,a.estimated_minutes
FROM student_activities sa
JOIN activities a ON a.id=sa.activity_id
WHERE sa.student_id=:id
ORDER BY
    CASE WHEN sa.status='pending' THEN 0 ELSE 1 END,
    COALESCE(sa.completed_at,sa.assigned_at) DESC
");
$stmt->execute(['id'=>$user['student_id']]);
$rows=$stmt->fetchAll();

$pageTitle='Minhas atividades';
require __DIR__.'/../../templates/header.php';
?>

<section class="panel">
<h2>Atividades</h2>

<?php foreach($rows as $row): ?>
<div class="list-card">
    <div style="display:flex;justify-content:space-between;gap:12px">
        <div>
            <strong><?= htmlspecialchars($row['title']) ?></strong>
            <p><?= htmlspecialchars($row['description'] ?? '') ?></p>
        </div>
        <span class="badge <?= $row['status']==='completed'?'success':'warning' ?>">
            <?= htmlspecialchars($row['status']) ?>
        </span>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:9px">
        <span class="badge"><?= htmlspecialchars($row['skill'] ?? '-') ?></span>
        <span class="badge"><?= (int)$row['estimated_minutes'] ?> min</span>
        <span class="badge"><?= (int)$row['xp_reward'] ?> XP</span>
        <?php if($row['score']!==null): ?>
            <span class="badge success"><?= number_format((float)$row['score'],0) ?>%</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if(!$rows): ?>
<div class="list-card"><strong>Nenhuma atividade ainda.</strong></div>
<?php endif; ?>
</section>

<?php require __DIR__.'/../../templates/footer.php'; ?>
