<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();

$rows=$pdo->query("
SELECT
    wr.id,wr.week_start,wr.week_end,wr.teacher_summary,wr.status,wr.created_at,
    s.id student_id,s.name student_name,s.phone
FROM weekly_reports wr
JOIN students s ON s.id=wr.student_id
ORDER BY wr.week_start DESC,s.name
LIMIT 100
")->fetchAll();

$pageTitle='Relatórios';
require __DIR__.'/../templates/header.php';
?>

<section class="panel">
<h2>Relatórios semanais</h2>
<p class="label">Resumo pedagógico armazenado por aluno e semana.</p>

<?php if(!$rows): ?>
<div class="list-card">
    <strong>Ainda não há relatórios.</strong>
    <p>Quando o workflow semanal for executado, eles aparecerão aqui.</p>
</div>
<?php endif; ?>

<?php foreach($rows as $row): ?>
<div class="list-card" style="margin-top:12px">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
        <div>
            <a href="/student.php?id=<?= urlencode($row['student_id']) ?>">
                <strong><?= htmlspecialchars($row['student_name']) ?></strong>
            </a>
            <p>
                <?= date('d/m/Y',strtotime($row['week_start'])) ?>
                a
                <?= date('d/m/Y',strtotime($row['week_end'])) ?>
            </p>
        </div>

        <span class="badge success"><?= htmlspecialchars($row['status']) ?></span>
    </div>

    <?php if($row['teacher_summary']): ?>
    <p style="white-space:pre-line;color:var(--text)">
        <?= htmlspecialchars($row['teacher_summary']) ?>
    </p>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
