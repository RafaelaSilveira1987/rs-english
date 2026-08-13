<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();
$level=$_GET['level'] ?? 'A1';

$stmt=$pdo->prepare("
SELECT *
FROM curriculum_modules
WHERE active=true AND level=:level
ORDER BY module_order,title
");
$stmt->execute(['level'=>$level]);
$modules=$stmt->fetchAll();

$pageTitle='Currículo';
require __DIR__.'/../templates/header.php';
?>

<section class="panel">
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
<?php foreach(['A1','A2','B1','B2','C1','C2'] as $l): ?>
    <a class="btn <?= $level===$l?'btn-primary':'btn-secondary' ?>"
       href="/curriculum.php?level=<?= $l ?>">
        <?= $l ?>
    </a>
<?php endforeach; ?>
</div>

<?php if(!$modules): ?>
<p class="label">Ainda não há módulos cadastrados para <?= htmlspecialchars($level) ?>.</p>
<?php endif; ?>

<div class="grid-3">
<?php foreach($modules as $module): ?>
<?php
$objectives=json_decode($module['objectives'] ?? '[]',true) ?: [];
$grammar=json_decode($module['grammar_topics'] ?? '[]',true) ?: [];
?>
<article class="card">
    <span class="badge"><?= htmlspecialchars($module['level']) ?></span>

    <h3 style="font-size:18px;margin:15px 0 8px">
        <?= htmlspecialchars($module['title']) ?>
    </h3>

    <p class="label"><?= htmlspecialchars($module['description'] ?? '') ?></p>

    <?php if($objectives): ?>
        <h3>Objetivos</h3>
        <?php foreach($objectives as $item): ?>
            <div class="list-card">
                <p style="margin:0"><?= htmlspecialchars((string)$item) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if($grammar): ?>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:12px">
        <?php foreach($grammar as $item): ?>
            <span class="badge"><?= htmlspecialchars((string)$item) ?></span>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</div>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
