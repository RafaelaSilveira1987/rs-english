<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();
$id=$_GET['id'] ?? '';

$stmt=$pdo->prepare("
SELECT
    s.*,
    sp.overall_level,sp.estimated_level,sp.goal,sp.correction_mode,
    sp.diagnostic_status,sp.diagnostic_step,
    sp.diagnostic_started_at,sp.diagnostic_completed_at,
    COALESCE(sp.grammar_score,0) grammar_score,
    COALESCE(sp.vocabulary_score,0) vocabulary_score,
    COALESCE(sp.speaking_score,0) speaking_score,
    COALESCE(sp.listening_score,0) listening_score,
    COALESCE(sp.reading_score,0) reading_score,
    COALESCE(sp.writing_score,0) writing_score,
    COALESCE(sp.fluency_score,0) fluency_score,
    COALESCE(sp.pronunciation_score,0) pronunciation_score,
    COALESCE(sp.xp,0) xp,
    COALESCE(sp.streak_days,0) streak_days,
    sp.last_study_at
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id=s.id
WHERE s.id=:id
");
$stmt->execute(['id'=>$id]);
$s=$stmt->fetch();

if(!$s){
    http_response_code(404);
    exit('Aluno não encontrado.');
}

$errorsStmt=$pdo->prepare("
SELECT *
FROM student_errors
WHERE student_id=:id AND status='learning'
ORDER BY occurrences DESC, mastery_score ASC
LIMIT 10
");
$errorsStmt->execute(['id'=>$id]);
$errors=$errorsStmt->fetchAll();

$vocabStmt=$pdo->prepare("
SELECT v.word,v.translation,v.example,
       sv.mastery_score,sv.next_review_at,sv.status
FROM student_vocabulary sv
JOIN vocabulary v ON v.id=sv.vocabulary_id
WHERE sv.student_id=:id
ORDER BY
  CASE WHEN sv.next_review_at IS NULL OR sv.next_review_at<=NOW() THEN 0 ELSE 1 END,
  sv.next_review_at NULLS FIRST
LIMIT 12
");
$vocabStmt->execute(['id'=>$id]);
$vocab=$vocabStmt->fetchAll();

$vocabStatsStmt=$pdo->prepare("
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
$vocabStatsStmt->execute(['id'=>$id]);
$vocabStats=$vocabStatsStmt->fetch();

$errorDueStmt=$pdo->prepare("
SELECT COUNT(*)
FROM student_errors
WHERE student_id=:id
  AND status='learning'
  AND (next_review_at IS NULL OR next_review_at<=NOW())
");
$errorDueStmt->execute(['id'=>$id]);
$errorDue=(int)$errorDueStmt->fetchColumn();

$sessionStmt=$pdo->prepare("
SELECT created_at,mode,topic,grammar_score,vocabulary_score,fluency_score
FROM sessions
WHERE student_id=:id
ORDER BY created_at DESC
LIMIT 8
");
$sessionStmt->execute(['id'=>$id]);
$sessions=$sessionStmt->fetchAll();

$planStmt=$pdo->prepare("
SELECT *
FROM study_plans
WHERE student_id=:id AND status='active'
ORDER BY created_at DESC
LIMIT 1
");
$planStmt->execute(['id'=>$id]);
$plan=$planStmt->fetch() ?: null;
$planData=$plan ? (json_decode($plan['plan_data'] ?? '{}',true) ?: []) : [];

$goalStmt=$pdo->prepare("
SELECT *
FROM weekly_goals
WHERE student_id=:id
ORDER BY week_start DESC
LIMIT 1
");
$goalStmt->execute(['id'=>$id]);
$goal=$goalStmt->fetch() ?: null;

$skills=[
 'Grammar'=>$s['grammar_score'],
 'Vocabulary'=>$s['vocabulary_score'],
 'Speaking'=>$s['speaking_score'],
 'Listening / Comprehension'=>$s['listening_score'],
 'Reading'=>$s['reading_score'],
 'Writing'=>$s['writing_score'],
 'Fluency'=>$s['fluency_score'],
 'Pronunciation'=>$s['pronunciation_score']
];

$pageTitle=$s['name'];
require __DIR__.'/../templates/header.php';
?>

<section class="student-head">
<div>
    <span class="badge dark"><?= htmlspecialchars($s['overall_level'] ?? 'A1') ?></span>
    <h2><?= htmlspecialchars($s['name']) ?></h2>
    <div class="label"><?= htmlspecialchars($s['phone'] ?? '') ?></div>
    <div style="margin-top:12px"><?= htmlspecialchars($s['goal'] ?? 'Aprender inglês') ?></div>
</div>

<div>
    <div style="font-size:28px;font-weight:900"><?= (int)$s['xp'] ?> XP</div>
    <div class="label"><?= (int)$s['streak_days'] ?> dias de sequência</div>
</div>
</section>

<section class="cards">
    <div class="card">
        <div class="label">Nível atual</div>
        <div class="metric"><?= htmlspecialchars($s['overall_level'] ?? 'A1') ?></div>
        <div class="metric-sub">Meta: <?= htmlspecialchars($plan['target_level'] ?? '-') ?></div>
    </div>

    <div class="card">
        <div class="label">Vocabulário em estudo</div>
        <div class="metric"><?= (int)($vocabStats['learning'] ?? 0) ?></div>
        <div class="metric-sub"><?= (int)($vocabStats['mastered'] ?? 0) ?> dominadas</div>
    </div>

    <div class="card">
        <div class="label">Revisões de hoje</div>
        <div class="metric"><?= (int)($vocabStats['due'] ?? 0)+$errorDue ?></div>
        <div class="metric-sub"><?= (int)($vocabStats['due'] ?? 0) ?> palavras + <?= $errorDue ?> gramática</div>
    </div>

    <div class="card">
        <div class="label">Diagnóstico</div>
        <div class="metric" style="font-size:22px"><?= htmlspecialchars($s['diagnostic_status'] ?? 'pending') ?></div>
        <div class="metric-sub">Passo <?= (int)($s['diagnostic_step'] ?? 0) ?></div>
    </div>
</section>

<div class="grid-2">
<section class="panel">
    <h2>Competências</h2>

    <?php foreach($skills as $name=>$score): ?>
    <div class="skill">
        <div class="skill-head">
            <span><?= htmlspecialchars($name) ?></span>
            <strong><?= number_format((float)$score,0) ?>%</strong>
        </div>
        <div class="progress">
            <span data-progress="<?= (float)$score ?>"></span>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<section class="panel">
    <h2>Meta semanal</h2>

    <?php if(!$goal): ?>
        <p class="label">Nenhuma meta semanal registrada ainda.</p>
    <?php else: ?>
        <?php
        $activitiesPct=$goal['target_activities']>0 ? min(100,($goal['completed_activities']/$goal['target_activities'])*100) : 0;
        $minutesPct=$goal['target_minutes']>0 ? min(100,($goal['completed_minutes']/$goal['target_minutes'])*100) : 0;
        $wordsPct=$goal['target_words']>0 ? min(100,($goal['learned_words']/$goal['target_words'])*100) : 0;
        ?>

        <div class="skill">
            <div class="skill-head">
                <span>Atividades</span>
                <strong><?= (int)$goal['completed_activities'] ?>/<?= (int)$goal['target_activities'] ?></strong>
            </div>
            <div class="progress"><span data-progress="<?= $activitiesPct ?>"></span></div>
        </div>

        <div class="skill">
            <div class="skill-head">
                <span>Minutos</span>
                <strong><?= (int)$goal['completed_minutes'] ?>/<?= (int)$goal['target_minutes'] ?></strong>
            </div>
            <div class="progress"><span data-progress="<?= $minutesPct ?>"></span></div>
        </div>

        <div class="skill">
            <div class="skill-head">
                <span>Palavras</span>
                <strong><?= (int)$goal['learned_words'] ?>/<?= (int)$goal['target_words'] ?></strong>
            </div>
            <div class="progress"><span data-progress="<?= $wordsPct ?>"></span></div>
        </div>
    <?php endif; ?>

    <h3>Plano</h3>

    <?php if(!$plan): ?>
        <p class="label">Plano ainda não criado.</p>
    <?php else: ?>
        <p><strong>Objetivo:</strong> <?= htmlspecialchars($plan['goal'] ?? '') ?></p>
        <p><strong>Meta:</strong> <span class="badge"><?= htmlspecialchars($plan['target_level'] ?? '') ?></span></p>
    <?php endif; ?>
</section>
</div>

<div class="grid-2" style="margin-top:20px">
<section class="panel">
    <h2>Erros recorrentes</h2>

    <?php if(!$errors): ?><p class="label">Nenhum erro recorrente.</p><?php endif; ?>

    <?php foreach($errors as $error): ?>
    <div class="list-card">
        <div style="display:flex;justify-content:space-between;gap:10px">
            <strong><?= htmlspecialchars($error['topic'] ?: $error['category'] ?: 'Correção') ?></strong>
            <span class="badge warning"><?= (int)$error['occurrences'] ?>x</span>
        </div>

        <p>
            <?= htmlspecialchars($error['original_text'] ?? '') ?>
            <?php if($error['corrected_text']): ?>
                → <?= htmlspecialchars($error['corrected_text']) ?>
            <?php endif; ?>
        </p>

        <div class="progress" style="margin-top:10px">
            <span data-progress="<?= (float)$error['mastery_score'] ?>"></span>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<section class="panel">
    <h2>Vocabulário</h2>

    <?php if(!$vocab): ?><p class="label">Nenhuma palavra registrada.</p><?php endif; ?>

    <?php foreach(array_slice($vocab,0,8) as $item): ?>
    <div class="list-card">
        <strong><?= htmlspecialchars($item['word']) ?></strong>

        <?php if($item['translation']): ?>
            <span class="badge"><?= htmlspecialchars($item['translation']) ?></span>
        <?php endif; ?>

        <p>
            Domínio: <?= number_format((float)$item['mastery_score'],0) ?>%
            <?php if($item['next_review_at']): ?>
                · revisão <?= date('d/m H:i',strtotime($item['next_review_at'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <?php endforeach; ?>
</section>
</div>

<section class="panel" style="margin-top:20px">
<h2>Sessões recentes</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Data</th>
    <th>Modo</th>
    <th>Tópico</th>
    <th>Grammar</th>
    <th>Vocabulary</th>
    <th>Fluency</th>
</tr>
</thead>
<tbody>
<?php foreach($sessions as $session): ?>
<tr>
    <td><?= date('d/m/Y H:i',strtotime($session['created_at'])) ?></td>
    <td><span class="badge"><?= htmlspecialchars($session['mode'] ?? '-') ?></span></td>
    <td><?= htmlspecialchars($session['topic'] ?? '-') ?></td>
    <td><?= $session['grammar_score']!==null ? number_format((float)$session['grammar_score'],0).'%' : '-' ?></td>
    <td><?= $session['vocabulary_score']!==null ? number_format((float)$session['vocabulary_score'],0).'%' : '-' ?></td>
    <td><?= $session['fluency_score']!==null ? number_format((float)$session['fluency_score'],0).'%' : '-' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
