<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$id = trim((string)($_GET['id'] ?? ''));

if ($id === '') {
    header('Location:/portal/activities.php');
    exit;
}

$stmt = $pdo->prepare(<<<'SQL'
    SELECT
        sa.id,
        sa.status,
        sa.assigned_at,
        sa.started_at,
        sa.completed_at,
        sa.score,
        sa.xp_earned,
        sa.attempts,
        sa.answer_text,
        sa.answer_data,
        sa.feedback,
        a.title,
        a.description,
        a.activity_type,
        a.skill,
        a.level,
        a.instructions,
        a.content,
        a.xp_reward,
        a.estimated_minutes
    FROM student_activities sa
    JOIN activities a ON a.id = sa.activity_id
    WHERE sa.id = :id
      AND sa.student_id = :student_id
    LIMIT 1
SQL);
$stmt->execute(['id' => $id, 'student_id' => $user['student_id']]);
$activity = $stmt->fetch();

if (!$activity) {
    http_response_code(404);
    exit('Atividade não encontrada.');
}

$content = portal_json($activity['content'] ?? null, []);
$options = portal_json($content['options'] ?? [], []);
$completed = $activity['status'] === 'completed';

if (!$completed && empty($activity['started_at'])) {
    $pdo->prepare('UPDATE student_activities SET started_at = NOW() WHERE id = :id')->execute(['id' => $id]);
}

$pageTitle = 'Atividade';
$pageSubtitle = 'Resolva a atividade e receba feedback sobre sua resposta.';
require __DIR__ . '/../../templates/header.php';
?>

<div class="activity-layout">
    <main class="activity-main">
        <section class="panel activity-detail-card">
            <div class="activity-detail-head">
                <div>
                    <div class="list-meta">
                        <span class="badge <?= e(ui_level_class((string)$activity['level'])) ?>"><?= e((string)$activity['level']) ?></span>
                        <span class="badge neutral"><?= e(ui_status_label((string)$activity['skill'])) ?></span>
                        <span class="badge neutral"><?= (int)$activity['estimated_minutes'] ?> min</span>
                        <span class="badge neutral"><?= (int)$activity['xp_reward'] ?> XP</span>
                    </div>
                    <h2><?= e((string)$activity['title']) ?></h2>
                    <p><?= e((string)($activity['description'] ?? '')) ?></p>
                </div>
                <span class="badge <?= e(ui_status_class((string)$activity['status'])) ?>"><?= e(ui_status_label((string)$activity['status'])) ?></span>
            </div>

            <div class="activity-instructions">
                <span><?= ui_icon('target', 'icon-sm') ?></span>
                <div><strong>Instruções</strong><p><?= nl2br(e((string)($activity['instructions'] ?: 'Responda da melhor forma possível.'))) ?></p></div>
            </div>

            <?php if (!empty($content['prompt'])): ?>
                <div class="activity-prompt"><?= nl2br(e((string)$content['prompt'])) ?></div>
            <?php elseif (!empty($content['question'])): ?>
                <div class="activity-prompt"><?= nl2br(e((string)$content['question'])) ?></div>
            <?php endif; ?>

            <?php if ($completed): ?>
                <div class="activity-result">
                    <div class="activity-result-score">
                        <span>Resultado</span>
                        <strong><?= $activity['score'] !== null ? number_format((float)$activity['score'], 0) . '%' : 'Concluída' ?></strong>
                        <small>+<?= (int)$activity['xp_earned'] ?> XP</small>
                    </div>
                    <div class="activity-result-copy">
                        <strong>Sua resposta</strong>
                        <p><?= nl2br(e((string)($activity['answer_text'] ?: 'Resposta registrada.'))) ?></p>
                        <strong>Feedback</strong>
                        <p><?= nl2br(e((string)($activity['feedback'] ?: 'Atividade concluída com sucesso.'))) ?></p>
                    </div>
                </div>
                <div class="form-actions"><a class="btn btn-primary" href="/portal/activities.php">Voltar às atividades</a><a class="btn btn-secondary" href="/portal/practice.php">Praticar com Emma</a></div>
            <?php else: ?>
                <form id="activity-answer-form" class="activity-answer-form" data-activity-id="<?= e((string)$activity['id']) ?>">
                    <?php if ($options): ?>
                        <fieldset class="activity-options">
                            <legend>Escolha uma alternativa</legend>
                            <?php foreach ($options as $index => $option):
                                $value = is_array($option) ? (string)($option['value'] ?? $option['label'] ?? $index) : (string)$option;
                                $label = is_array($option) ? (string)($option['label'] ?? $option['value'] ?? '') : (string)$option;
                            ?>
                                <label class="activity-option"><input type="radio" name="answer" value="<?= e($value) ?>" required><span><?= e($label) ?></span></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php else: ?>
                        <div class="form-row">
                            <label for="activity-answer">Sua resposta</label>
                            <textarea id="activity-answer" name="answer" rows="7" required placeholder="Escreva sua resposta aqui..."></textarea>
                            <small class="form-help">Responda em inglês sempre que a atividade pedir produção de texto.</small>
                        </div>
                    <?php endif; ?>
                    <div id="activity-submit-feedback" class="activity-submit-feedback" hidden></div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Enviar resposta</button><a class="btn btn-secondary" href="/portal/activities.php">Voltar</a></div>
                </form>
            <?php endif; ?>
        </section>
    </main>

    <aside class="activity-sidebar">
        <section class="panel">
            <h2>Sobre esta atividade</h2>
            <div class="info-grid">
                <div class="info-item"><span>Habilidade</span><strong><?= e(ui_status_label((string)$activity['skill'])) ?></strong></div>
                <div class="info-item"><span>Tipo</span><strong><?= e(ui_status_label((string)$activity['activity_type'])) ?></strong></div>
                <div class="info-item"><span>Tentativas</span><strong><?= (int)$activity['attempts'] ?></strong></div>
                <div class="info-item"><span>Data</span><strong><?= e(ui_date_only((string)$activity['assigned_at'])) ?></strong></div>
            </div>
        </section>
        <section class="panel">
            <h2>Dica da Emma</h2>
            <div class="teacher-tip"><div class="avatar avatar-sm">E</div><p>Leia a instrução com calma. Uma resposta simples e correta é melhor do que uma frase longa sem clareza.</p></div>
        </section>
    </aside>
</div>

<script>
document.getElementById('activity-answer-form')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const button = this.querySelector('button[type="submit"]');
    const feedback = document.getElementById('activity-submit-feedback');
    const formData = new FormData(this);
    const answer = String(formData.get('answer') || '').trim();

    if (!answer) return;

    button.disabled = true;
    button.textContent = 'Avaliando...';
    feedback.hidden = true;

    try {
        const response = await fetch('/api/web/activity-submit.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({student_activity_id: this.dataset.activityId, answer})
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Não foi possível concluir a atividade.');

        feedback.className = 'activity-submit-feedback success';
        feedback.innerHTML = `<strong>${data.score !== null ? data.score + '% · ' : ''}+${data.xp_earned || 0} XP</strong><p>${data.feedback || 'Atividade concluída.'}</p>`;
        feedback.hidden = false;
        setTimeout(() => window.location.reload(), 1200);
    } catch (error) {
        feedback.className = 'activity-submit-feedback danger';
        feedback.textContent = error.message;
        feedback.hidden = false;
        button.disabled = false;
        button.textContent = 'Enviar resposta';
    }
});
</script>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
