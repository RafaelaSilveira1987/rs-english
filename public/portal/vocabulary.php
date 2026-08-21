<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$stmt = $pdo->prepare(<<<'SQL'
    SELECT
        v.word, v.translation, v.definition_en, v.example, v.level, v.category,
        sv.status, sv.mastery_score, sv.repetitions, sv.correct_answers,
        sv.incorrect_answers, sv.first_seen_at, sv.last_seen_at,
        sv.next_review_at, COALESCE(sv.source, 'conversation') AS source
    FROM student_vocabulary sv
    JOIN vocabulary v ON v.id = sv.vocabulary_id
    WHERE sv.student_id = :id
    ORDER BY
        CASE
            WHEN sv.status IN ('learning','review')
             AND (sv.next_review_at IS NULL OR sv.next_review_at <= NOW()) THEN 0
            WHEN sv.status = 'review' THEN 1
            WHEN sv.status = 'learning' THEN 2
            ELSE 3
        END,
        sv.last_seen_at DESC NULLS LAST,
        v.word
SQL);
$stmt->execute(['id' => $user['student_id']]);
$rows = $stmt->fetchAll();
$mastered = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'mastered'));
$due = count(array_filter($rows, static fn(array $r): bool => in_array($r['status'], ['learning','review'], true) && (!$r['next_review_at'] || strtotime((string)$r['next_review_at']) <= time())));
$average = $rows ? array_sum(array_map(static fn(array $r): float => (float)$r['mastery_score'], $rows)) / count($rows) : 0;
$sourceLabels = [
    'conversation' => 'Conversa com a Emma',
    'diagnostic' => 'Diagnóstico',
    'activity' => 'Atividade',
    'teacher_interaction' => 'Conversa com a Emma',
];

$pageTitle = 'Meu vocabulário';
$pageSubtitle = 'Palavras e expressões relevantes identificadas nas suas conversas, diagnóstico e atividades.';
require __DIR__ . '/../../templates/header.php';
?>
<section class="cards cards-3">
    <article class="card metric-card"><div><div class="label">Palavras acompanhadas</div><div class="metric"><?= count($rows) ?></div><div class="metric-sub">Total registrado no seu repertório</div></div><div class="metric-icon"><?= ui_icon('vocabulary') ?></div></article>
    <article class="card metric-card"><div><div class="label">Dominadas</div><div class="metric"><?= $mastered ?></div><div class="metric-sub">Com domínio consolidado</div></div><div class="metric-icon"><?= ui_icon('sparkles') ?></div></article>
    <article class="card metric-card"><div><div class="label">Revisar agora</div><div class="metric"><?= $due ?></div><div class="metric-sub">Domínio médio <?= number_format($average, 0) ?>%</div></div><div class="metric-icon"><?= ui_icon('plan') ?></div></article>
</section>

<section class="notice section-gap-sm">
    <?= ui_icon('info', 'icon-sm') ?>
    <span><strong>Como uma palavra entra aqui:</strong> a Emma registra até 8 palavras ou expressões relevantes por avaliação, excluindo palavras funcionais muito simples. Ela pode vir de conversa, diagnóstico ou atividade. A primeira revisão é sugerida para o dia seguinte e o domínio cresce com acertos e revisões.</span>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Palavras acompanhadas</h2><p>O domínio é atualizado conforme seus acertos, uso em frases e revisões.</p></div></div>
    <?php if (!$rows): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><?= ui_icon('vocabulary') ?></div>
            <h3>Ainda não há palavras registradas</h3>
            <p>As próximas conversas, etapas do diagnóstico e atividades avaliadas começarão a preencher esta área automaticamente.</p>
            <a class="btn btn-primary btn-sm" href="/portal/practice.php">Praticar com Emma</a>
        </div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Palavra</th><th>Significado</th><th>Origem</th><th>Domínio</th><th>Status</th><th>Revisão</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><strong><?= e((string)$row['word']) ?></strong><?php if ($row['example']): ?><div class="label"><?= e(portal_clean_text($row['example'])) ?></div><?php endif; ?></td>
                <td><?= e((string)($row['translation'] ?: $row['definition_en'] ?: 'Em construção')) ?></td>
                <td><span class="badge neutral"><?= e($sourceLabels[(string)$row['source']] ?? 'Aprendizagem') ?></span><div class="label">Visto <?= e(ui_relative_date($row['last_seen_at'] ?: $row['first_seen_at'])) ?></div></td>
                <td style="min-width:150px"><div class="skill-head"><span><?= number_format((float)$row['mastery_score'], 0) ?>%</span></div><div class="progress slim"><span data-progress="<?= ui_percent($row['mastery_score']) ?>"></span></div></td>
                <td><span class="badge <?= e(ui_status_class((string)$row['status'])) ?>"><?= e(ui_status_label((string)$row['status'])) ?></span></td>
                <td><?= e(ui_date($row['next_review_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../templates/footer.php'; ?>
