<?php
declare(strict_types=1);

require_once __DIR__.'/../../src/db.php';
require_once __DIR__.'/../../src/auth.php';

$user=require_student();
$pdo=db();
$studentId=$user['student_id'];

$stmt=$pdo->prepare("
SELECT s.*,sp.*
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id=s.id
WHERE s.id=:id
LIMIT 1
");
$stmt->execute(['id'=>$studentId]);
$s=$stmt->fetch();

$vocabStats=$pdo->prepare("
SELECT
 COUNT(*) FILTER(WHERE status='mastered') mastered,
 COUNT(*) FILTER(WHERE status IN ('learning','review')) learning,
 COUNT(*) FILTER(
   WHERE status IN ('learning','review')
   AND (next_review_at IS NULL OR next_review_at<=NOW())
 ) due
FROM student_vocabulary
WHERE student_id=:id
");
$vocabStats->execute(['id'=>$studentId]);
$vocab=$vocabStats->fetch();

$errorStmt=$pdo->prepare("
SELECT COUNT(*)
FROM student_errors
WHERE student_id=:id
  AND status='learning'
  AND (next_review_at IS NULL OR next_review_at<=NOW())
");
$errorStmt->execute(['id'=>$studentId]);
$errorDue=(int)$errorStmt->fetchColumn();

$activityStmt=$pdo->prepare("
SELECT COUNT(*)
FROM student_activities
WHERE student_id=:id AND status='pending'
");
$activityStmt->execute(['id'=>$studentId]);
$pendingActivities=(int)$activityStmt->fetchColumn();

$goalStmt=$pdo->prepare("
SELECT *
FROM weekly_goals
WHERE student_id=:id
ORDER BY week_start DESC
LIMIT 1
");
$goalStmt->execute(['id'=>$studentId]);
$goal=$goalStmt->fetch() ?: null;

$planStmt=$pdo->prepare("
SELECT *
FROM study_plans
WHERE student_id=:id AND status='active'
ORDER BY created_at DESC
LIMIT 1
");
$planStmt->execute(['id'=>$studentId]);
$plan=$planStmt->fetch() ?: null;

$skills=[
 'Grammar'=>(float)($s['grammar_score'] ?? 0),
 'Vocabulary'=>(float)($s['vocabulary_score'] ?? 0),
 'Speaking'=>(float)($s['speaking_score'] ?? 0),
 'Listening'=>(float)($s['listening_score'] ?? 0),
 'Fluency'=>(float)($s['fluency_score'] ?? 0),
];

$pageTitle='Meu progresso';
require __DIR__.'/../../templates/header.php';
?>

<section class="student-head">
<div>
    <span class="badge dark"><?= htmlspecialchars($s['overall_level'] ?? 'A1') ?></span>
    <h2>Hi, <?= htmlspecialchars($s['name']) ?> 👋</h2>
    <div class="label"><?= htmlspecialchars($s['goal'] ?? 'Aprender inglês') ?></div>
</div>

<div>
    <div style="font-size:28px;font-weight:900"><?= (int)($s['xp'] ?? 0) ?> XP</div>
    <div class="label"><?= (int)($s['streak_days'] ?? 0) ?> dias de sequência</div>
</div>
</section>

<section class="cards">
    <div class="card">
        <div class="label">Nível atual</div>
        <div class="metric"><?= htmlspecialchars($s['overall_level'] ?? 'A1') ?></div>
        <div class="metric-sub">Meta: <?= htmlspecialchars($plan['target_level'] ?? '-') ?></div>
    </div>

    <div class="card">
        <div class="label">Revisões de hoje</div>
        <div class="metric"><?= (int)($vocab['due'] ?? 0)+$errorDue ?></div>
        <div class="metric-sub">Vocabulário + gramática</div>
    </div>

    <div class="card">
        <div class="label">Atividades pendentes</div>
        <div class="metric"><?= $pendingActivities ?></div>
        <div class="metric-sub">Seu próximo passo</div>
    </div>

    <div class="card">
        <div class="label">Palavras dominadas</div>
        <div class="metric"><?= (int)($vocab['mastered'] ?? 0) ?></div>
        <div class="metric-sub"><?= (int)($vocab['learning'] ?? 0) ?> em aprendizado</div>
    </div>
</section>

<div class="grid-2">
<section class="panel">
<h2>Suas competências</h2>

<?php foreach($skills as $name=>$score): ?>
<div class="skill">
    <div class="skill-head">
        <span><?= htmlspecialchars($name) ?></span>
        <strong><?= number_format($score,0) ?>%</strong>
    </div>
    <div class="progress"><span data-progress="<?= $score ?>"></span></div>
</div>
<?php endforeach; ?>
</section>

<section class="panel">
<h2>Próximo passo</h2>

<div class="list-card">
    <strong>Praticar com Emma</strong>
    <p>Use o canal Web enquanto o WhatsApp está indisponível.</p>
    <a class="btn btn-primary" href="/portal/practice.php" style="margin-top:10px">Começar</a>
</div>

<div class="list-card">
    <strong>Revisões</strong>
    <p><?= (int)($vocab['due'] ?? 0)+$errorDue ?> itens precisam aparecer novamente.</p>
</div>

<div class="list-card">
    <strong>Atividades</strong>
    <p><?= $pendingActivities ?> atividade(s) pendente(s).</p>
</div>
</section>
</div>

<?php require __DIR__.'/../../templates/footer.php'; ?>
