<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();

$stats=[
    'pending'=>(int)$pdo->query("SELECT COUNT(*) FROM student_activities WHERE status='pending'")->fetchColumn(),
    'completed'=>(int)$pdo->query("SELECT COUNT(*) FROM student_activities WHERE status='completed'")->fetchColumn(),
    'avg'=>(float)$pdo->query("SELECT COALESCE(AVG(score),0) FROM student_activities WHERE status='completed'")->fetchColumn()
];

$rows=$pdo->query("
SELECT
    sa.id,sa.status,sa.assigned_at,sa.completed_at,sa.score,sa.xp_earned,
    s.id student_id,s.name student_name,
    a.title,a.activity_type,a.skill,a.level,a.xp_reward
FROM student_activities sa
JOIN students s ON s.id=sa.student_id
JOIN activities a ON a.id=sa.activity_id
ORDER BY COALESCE(sa.completed_at,sa.assigned_at) DESC
LIMIT 100
")->fetchAll();

$pageTitle='Atividades';
require __DIR__.'/../templates/header.php';
?>

<section class="cards">
    <div class="card">
        <div class="label">Pendentes</div>
        <div class="metric"><?= $stats['pending'] ?></div>
    </div>

    <div class="card">
        <div class="label">Concluídas</div>
        <div class="metric"><?= $stats['completed'] ?></div>
    </div>

    <div class="card">
        <div class="label">Média</div>
        <div class="metric"><?= number_format($stats['avg'],0) ?>%</div>
    </div>

    <div class="card">
        <div class="label">Motor</div>
        <div class="metric" style="font-size:21px">Personalizado</div>
        <div class="metric-sub">Baseado em erros e vocabulário</div>
    </div>
</section>

<section class="panel">
<h2>Histórico</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Aluno</th>
    <th>Atividade</th>
    <th>Tipo</th>
    <th>Skill</th>
    <th>Status</th>
    <th>Nota</th>
    <th>XP</th>
</tr>
</thead>
<tbody>
<?php foreach($rows as $row): ?>
<tr>
    <td>
        <a href="/student.php?id=<?= urlencode($row['student_id']) ?>">
            <strong><?= htmlspecialchars($row['student_name']) ?></strong>
        </a>
    </td>
    <td><?= htmlspecialchars($row['title']) ?></td>
    <td><span class="badge"><?= htmlspecialchars($row['activity_type'] ?? '-') ?></span></td>
    <td><?= htmlspecialchars($row['skill'] ?? '-') ?></td>
    <td>
        <span class="badge <?= $row['status']==='completed'?'success':'warning' ?>">
            <?= htmlspecialchars($row['status']) ?>
        </span>
    </td>
    <td><?= $row['score']!==null ? number_format((float)$row['score'],0).'%' : '-' ?></td>
    <td><?= (int)$row['xp_earned'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
