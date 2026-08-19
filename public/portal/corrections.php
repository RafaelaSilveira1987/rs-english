<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$studentId = (string)$user['student_id'];
$type = trim((string)($_GET['type'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$sql = <<<'SQL'
    SELECT
        se.id,
        se.category AS correction_type,
        se.topic,
        se.original_text,
        se.corrected_text,
        se.explanation,
        se.severity,
        se.occurrences,
        se.mastery_score,
        se.status,
        se.next_review_at,
        se.created_at,
        'student_error' AS source
    FROM student_errors se
    WHERE se.student_id = :student_id
SQL;
$params = ['student_id' => $studentId];

if ($type !== '') {
    $sql .= ' AND (se.category = :type OR se.topic = :type)';
    $params['type'] = $type;
}

if (in_array($status, ['learning', 'mastered'], true)) {
    $sql .= ' AND se.status = :status';
    $params['status'] = $status;
}

$sql .= ' ORDER BY CASE WHEN se.status = \'learning\' THEN 0 ELSE 1 END, se.occurrences DESC, se.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statsStmt = $pdo->prepare(<<<'SQL'
    SELECT
        COUNT(*) FILTER (WHERE status = 'learning') AS learning,
        COUNT(*) FILTER (WHERE status = 'mastered') AS mastered,
        COUNT(*) FILTER (WHERE status = 'learning' AND (next_review_at IS NULL OR next_review_at <= NOW())) AS due,
        COALESCE(SUM(occurrences), 0) AS occurrences
    FROM student_errors
    WHERE student_id = :student_id
SQL);
$statsStmt->execute(['student_id' => $studentId]);
$stats = $statsStmt->fetch() ?: [];

$categoriesStmt = $pdo->prepare(<<<'SQL'
    SELECT COALESCE(NULLIF(category, ''), 'other') AS category, COUNT(*) AS total
    FROM student_errors
    WHERE student_id = :student_id
    GROUP BY COALESCE(NULLIF(category, ''), 'other')
    ORDER BY total DESC, category
SQL);
$categoriesStmt->execute(['student_id' => $studentId]);
$categories = $categoriesStmt->fetchAll();

$pageTitle = 'Minhas correções';
$pageSubtitle = 'Revise os erros mais importantes identificados durante suas conversas e atividades.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="cards cards-4">
    <article class="card metric-card"><div><div class="label">Em aprendizado</div><div class="metric"><?= (int)($stats['learning'] ?? 0) ?></div><div class="metric-sub">Pontos ativos</div></div><div class="metric-icon"><?= ui_icon('corrections') ?></div></article>
    <article class="card metric-card"><div><div class="label">Para revisar hoje</div><div class="metric"><?= (int)($stats['due'] ?? 0) ?></div><div class="metric-sub">Revisões vencidas</div></div><div class="metric-icon"><?= ui_icon('review') ?></div></article>
    <article class="card metric-card"><div><div class="label">Dominadas</div><div class="metric"><?= (int)($stats['mastered'] ?? 0) ?></div><div class="metric-sub">Pontos superados</div></div><div class="metric-icon"><?= ui_icon('progress') ?></div></article>
    <article class="card metric-card"><div><div class="label">Ocorrências</div><div class="metric"><?= (int)($stats['occurrences'] ?? 0) ?></div><div class="metric-sub">Registros analisados</div></div><div class="metric-icon"><?= ui_icon('history') ?></div></article>
</section>

<section class="panel">
    <div class="panel-head">
        <div><h2>Histórico de correções</h2><p>Use os filtros para localizar uma habilidade específica.</p></div>
    </div>
    <form method="get" class="filter-row filter-form">
        <select name="type">
            <option value="">Todas as categorias</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e((string)$category['category']) ?>" <?= $type === $category['category'] ? 'selected' : '' ?>>
                    <?= e(ui_status_label((string)$category['category'])) ?> (<?= (int)$category['total'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">Todos os status</option>
            <option value="learning" <?= $status === 'learning' ? 'selected' : '' ?>>Em aprendizado</option>
            <option value="mastered" <?= $status === 'mastered' ? 'selected' : '' ?>>Dominadas</option>
        </select>
        <button class="btn btn-primary btn-sm">Filtrar</button>
        <?php if ($type !== '' || $status !== ''): ?><a class="btn btn-secondary btn-sm" href="/portal/corrections.php">Limpar</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><?= ui_icon('corrections') ?></div>
            <h3>Nenhuma correção neste filtro</h3>
            <p>As correções registradas pela Emma aparecerão aqui.</p>
            <a class="btn btn-primary btn-sm" href="/portal/practice.php">Praticar com Emma</a>
        </div>
    <?php else: ?>
        <div class="correction-list">
            <?php foreach ($rows as $row):
                $mastery = max(0, min(100, (float)($row['mastery_score'] ?? 0)));
                $severity = strtolower((string)($row['severity'] ?? 'medium'));
            ?>
                <article class="correction-card">
                    <div class="correction-card-head">
                        <div>
                            <span class="badge neutral"><?= e(ui_status_label((string)($row['correction_type'] ?: 'grammar'))) ?></span>
                            <?php if (!empty($row['topic'])): ?><span class="badge neutral"><?= e(str_replace('_', ' ', (string)$row['topic'])) ?></span><?php endif; ?>
                            <span class="badge <?= e(ui_status_class((string)$row['status'])) ?>"><?= e(ui_status_label((string)$row['status'])) ?></span>
                        </div>
                        <span class="severity severity-<?= e($severity) ?>"><?= e(ui_status_label($severity)) ?></span>
                    </div>

                    <div class="correction-comparison">
                        <div class="correction-before">
                            <span>Você escreveu</span>
                            <p><?= e((string)($row['original_text'] ?: 'Trecho não registrado')) ?></p>
                        </div>
                        <div class="correction-arrow">→</div>
                        <div class="correction-after">
                            <span>Forma recomendada</span>
                            <p><?= e((string)($row['corrected_text'] ?: 'Correção em preparação')) ?></p>
                        </div>
                    </div>

                    <?php if (!empty($row['explanation'])): ?>
                        <div class="correction-explanation">
                            <?= ui_icon('sparkles', 'icon-sm') ?>
                            <p><?= e((string)$row['explanation']) ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="correction-footer">
                        <div>
                            <span>Domínio</span>
                            <div class="progress"><span data-progress="<?= $mastery ?>"></span></div>
                            <strong><?= number_format($mastery, 0) ?>%</strong>
                        </div>
                        <div class="correction-meta">
                            <span><?= (int)$row['occurrences'] ?> ocorrência(s)</span>
                            <span>Registrada em <?= e(ui_date((string)$row['created_at'])) ?></span>
                            <?php if (!empty($row['next_review_at']) && $row['status'] === 'learning'): ?><span>Próxima revisão: <?= e(ui_date((string)$row['next_review_at'])) ?></span><?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
