<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_login();

$pdo = db();
$id = $_GET['id'] ?? '';

$stmt = $pdo->prepare("
SELECT s.*,
       sp.overall_level, sp.estimated_level, sp.goal, sp.correction_mode,
       sp.diagnostic_status, sp.diagnostic_step,
       sp.diagnostic_started_at, sp.diagnostic_completed_at,
       COALESCE(sp.grammar_score,0) grammar_score,
       COALESCE(sp.vocabulary_score,0) vocabulary_score,
       COALESCE(sp.speaking_score,0) speaking_score,
       COALESCE(sp.listening_score,0) listening_score,
       COALESCE(sp.reading_score,0) reading_score,
       COALESCE(sp.writing_score,0) writing_score,
       COALESCE(sp.fluency_score,0) fluency_score,
       COALESCE(sp.pronunciation_score,0) pronunciation_score,
       COALESCE(sp.xp,0) xp,
       COALESCE(sp.streak_days,0) streak_days
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id = s.id
WHERE s.id = :id
");
$stmt->execute(['id' => $id]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Aluno não encontrado.');
}

$errorsStmt = $pdo->prepare("
SELECT category, topic, original_text, corrected_text, severity, occurrences, created_at
FROM student_errors
WHERE student_id = :id
ORDER BY occurrences DESC, created_at DESC
LIMIT 10
");
$errorsStmt->execute(['id' => $id]);
$errors = $errorsStmt->fetchAll();

$sessionsStmt = $pdo->prepare("
SELECT id, created_at, mode, topic, grammar_score, vocabulary_score, fluency_score
FROM sessions
WHERE student_id = :id
ORDER BY created_at DESC
LIMIT 10
");
$sessionsStmt->execute(['id' => $id]);
$sessions = $sessionsStmt->fetchAll();

$assessmentStmt = $pdo->prepare("
SELECT ar.*, a.title
FROM assessment_results ar
LEFT JOIN assessments a ON a.id = ar.assessment_id
WHERE ar.student_id = :id
ORDER BY ar.created_at DESC
LIMIT 1
");
$assessmentStmt->execute(['id' => $id]);
$assessment = $assessmentStmt->fetch() ?: null;

$planStmt = $pdo->prepare("
SELECT *
FROM study_plans
WHERE student_id = :id
  AND status = 'active'
ORDER BY created_at DESC
LIMIT 1
");
$planStmt->execute(['id' => $id]);
$plan = $planStmt->fetch() ?: null;
$planData = $plan ? json_decode($plan['plan_data'] ?? '{}', true) : [];

$skills = [
    'Grammar' => $student['grammar_score'],
    'Vocabulary' => $student['vocabulary_score'],
    'Speaking' => $student['speaking_score'],
    'Listening / Comprehension' => $student['listening_score'],
    'Reading' => $student['reading_score'],
    'Writing' => $student['writing_score'],
    'Fluency' => $student['fluency_score'],
    'Pronunciation' => $student['pronunciation_score'],
];

$pageTitle = $student['name'];
require __DIR__ . '/../templates/header.php';
?>

<div class="student-head">
    <div>
        <span class="badge"><?= htmlspecialchars($student['overall_level'] ?? 'A1') ?></span>
        <p><?= htmlspecialchars($student['phone'] ?? '') ?></p>
        <p class="label"><?= htmlspecialchars($student['goal'] ?? 'Sem objetivo definido') ?></p>
    </div>
    <div>
        <strong><?= (int)$student['xp'] ?> XP</strong><br>
        <span class="label"><?= (int)$student['streak_days'] ?> dias de sequência</span>
    </div>
</div>

<section class="cards">
    <div class="card">
        <div class="label">Diagnóstico</div>
        <div class="metric" style="font-size:20px"><?= htmlspecialchars($student['diagnostic_status'] ?? 'pending') ?></div>
    </div>
    <div class="card">
        <div class="label">Nível atual</div>
        <div class="metric"><?= htmlspecialchars($student['overall_level'] ?? 'A1') ?></div>
    </div>
    <div class="card">
        <div class="label">Meta do plano</div>
        <div class="metric"><?= htmlspecialchars($plan['target_level'] ?? '-') ?></div>
    </div>
    <div class="card">
        <div class="label">Plano</div>
        <div class="metric" style="font-size:20px"><?= $plan ? 'Ativo' : 'Pendente' ?></div>
    </div>
</section>

<div class="grid-2">
    <section class="panel">
        <h2>Competências</h2>
        <?php foreach ($skills as $name => $score): ?>
            <div class="skill">
                <div class="skill-head">
                    <span><?= htmlspecialchars($name) ?></span>
                    <strong><?= number_format((float)$score,0) ?>%</strong>
                </div>
                <div class="progress"><span data-progress="<?= (float)$score ?>"></span></div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Plano de estudo</h2>
        <?php if (!$plan): ?>
            <p class="label">O plano será criado após concluir o diagnóstico.</p>
        <?php else: ?>
            <p><strong>Objetivo:</strong> <?= htmlspecialchars($plan['goal'] ?? '') ?></p>
            <p><strong>Meta:</strong> <?= htmlspecialchars($plan['target_level'] ?? '') ?></p>

            <?php if (!empty($planData['focus'])): ?>
                <h3>Foco</h3>
                <ul>
                    <?php foreach ($planData['focus'] as $item): ?>
                        <li><?= htmlspecialchars((string)$item) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php for ($week=1; $week<=4; $week++): ?>
                <?php $key = 'week_' . $week; ?>
                <?php if (!empty($planData[$key])): ?>
                    <h3>Semana <?= $week ?></h3>
                    <ul>
                        <?php foreach ($planData[$key] as $item): ?>
                            <li><?= htmlspecialchars((string)$item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endfor; ?>
        <?php endif; ?>
    </section>
</div>

<div class="grid-2" style="margin-top:20px">
    <section class="panel">
        <h2>Último diagnóstico</h2>
        <?php if (!$assessment): ?>
            <p class="label">Nenhuma avaliação concluída ainda.</p>
        <?php else: ?>
            <p><strong><?= htmlspecialchars($assessment['title'] ?? 'Avaliação') ?></strong></p>
            <p>Nível: <span class="badge"><?= htmlspecialchars($assessment['overall_level'] ?? '-') ?></span></p>
            <p>Nota geral: <strong><?= number_format((float)$assessment['total_score'],0) ?>%</strong></p>
            <?php
                $strengths = json_decode($assessment['strengths'] ?? '[]', true) ?: [];
                $weaknesses = json_decode($assessment['weaknesses'] ?? '[]', true) ?: [];
            ?>
            <?php if ($strengths): ?>
                <h3>Pontos fortes</h3>
                <ul><?php foreach ($strengths as $x): ?><li><?= htmlspecialchars((string)$x) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($weaknesses): ?>
                <h3>Prioridades</h3>
                <ul><?php foreach ($weaknesses as $x): ?><li><?= htmlspecialchars((string)$x) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Erros recorrentes</h2>
        <?php if (!$errors): ?>
            <p class="label">Nenhum erro registrado ainda.</p>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div style="margin-bottom:16px">
                <strong><?= htmlspecialchars($error['topic'] ?: $error['category'] ?: 'Correção') ?></strong>
                <span class="badge"><?= (int)$error['occurrences'] ?>x</span>
                <div class="label">
                    <?= htmlspecialchars($error['original_text'] ?? '') ?>
                    →
                    <?= htmlspecialchars($error['corrected_text'] ?? '') ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
</div>

<section class="panel" style="margin-top:20px">
    <h2>Sessões recentes</h2>
    <table>
        <thead>
        <tr><th>Data</th><th>Modo</th><th>Tópico</th><th>Grammar</th><th>Vocabulary</th><th>Fluency</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sessions as $session): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($session['created_at'])) ?></td>
                <td><?= htmlspecialchars($session['mode'] ?? '-') ?></td>
                <td><?= htmlspecialchars($session['topic'] ?? '-') ?></td>
                <td><?= $session['grammar_score'] !== null ? number_format((float)$session['grammar_score'],0).'%' : '-' ?></td>
                <td><?= $session['vocabulary_score'] !== null ? number_format((float)$session['vocabulary_score'],0).'%' : '-' ?></td>
                <td><?= $session['fluency_score'] !== null ? number_format((float)$session['fluency_score'],0).'%' : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
