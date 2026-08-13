<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_login(); $pdo=db();
$rows=$pdo->query("SELECT wr.*,s.id student_id,s.name student_name FROM weekly_reports wr JOIN students s ON s.id=wr.student_id ORDER BY wr.week_start DESC,s.name LIMIT 100")->fetchAll();
$pageTitle='Relatórios'; require __DIR__.'/../templates/header.php'; ?>
<section class="panel"><h2>Relatórios semanais</h2><p class="label">Gerados pelo workflow semanal do n8n e armazenados no PostgreSQL.</p><?php if(!$rows): ?><div class="list-card"><strong>Ainda não há relatórios.</strong><p>Depois de ativar o workflow semanal, eles aparecerão aqui.</p></div><?php endif; ?><?php foreach($rows as $r): ?><div class="list-card" style="margin-top:12px"><div style="display:flex;justify-content:space-between;gap:12px"><div><a href="/student.php?id=<?= urlencode($r['student_id']) ?>"><strong><?= htmlspecialchars($r['student_name']) ?></strong></a><p><?= date('d/m/Y',strtotime($r['week_start'])) ?> a <?= date('d/m/Y',strtotime($r['week_end'])) ?></p></div><span class="badge success"><?= htmlspecialchars($r['status']) ?></span></div><?php if($r['teacher_summary']): ?><p style="white-space:pre-line;color:var(--text)"><?= htmlspecialchars($r['teacher_summary']) ?></p><?php endif; ?></div><?php endforeach; ?></section>
<?php require __DIR__.'/../templates/footer.php'; ?>
