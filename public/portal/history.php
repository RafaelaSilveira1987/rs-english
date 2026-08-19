<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$studentId = (string)$user['student_id'];
$type = trim((string)($_GET['type'] ?? ''));

$sql = <<<'SQL'
    SELECT * FROM (
        SELECT
            m.id,
            CASE WHEN m.role = 'teacher' THEN 'teacher_message' ELSE 'student_message' END AS event_type,
            CASE WHEN m.role = 'teacher' THEN 'Resposta da Emma' ELSE 'Mensagem enviada' END AS title,
            COALESCE(NULLIF(m.content, ''), m.transcription, '') AS description,
            m.message_type AS meta,
            m.created_at
        FROM messages m
        WHERE m.student_id = :student_id

        UNION ALL

        SELECT
            sa.id,
            'activity' AS event_type,
            CASE WHEN sa.status = 'completed' THEN 'Atividade concluída' ELSE 'Atividade atribuída' END AS title,
            a.title AS description,
            CONCAT(COALESCE(sa.score::text, ''), CASE WHEN sa.score IS NULL THEN '' ELSE '%' END) AS meta,
            COALESCE(sa.completed_at, sa.assigned_at) AS created_at
        FROM student_activities sa
        JOIN activities a ON a.id = sa.activity_id
        WHERE sa.student_id = :student_id

        UNION ALL

        SELECT
            se.id,
            'correction' AS event_type,
            'Correção registrada' AS title,
            COALESCE(se.original_text, '') || CASE WHEN se.corrected_text IS NULL THEN '' ELSE ' → ' || se.corrected_text END AS description,
            COALESCE(se.category, 'grammar') AS meta,
            se.created_at
        FROM student_errors se
        WHERE se.student_id = :student_id

        UNION ALL

        SELECT
            vc.id,
            'voice' AS event_type,
            'Prática por áudio' AS title,
            COALESCE(vc.student_transcription, vc.teacher_text, 'Conversa por voz') AS description,
            vc.status AS meta,
            vc.created_at
        FROM voice_conversations vc
        WHERE vc.student_id = :student_id

        UNION ALL

        SELECT
            e.id,
            e.event_type,
            e.title,
            COALESCE(e.description, '') AS description,
            '' AS meta,
            e.created_at
        FROM study_events e
        WHERE e.student_id = :student_id
    ) timeline
SQL;
$params = ['student_id' => $studentId];
if ($type !== '') {
    $sql .= ' WHERE event_type = :event_type';
    $params['event_type'] = $type;
}
$sql .= ' ORDER BY created_at DESC LIMIT 250';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$groups = [];
foreach ($events as $event) {
    $dateKey = (new DateTimeImmutable((string)$event['created_at']))->format('Y-m-d');
    $groups[$dateKey][] = $event;
}

$icons = [
    'teacher_message' => 'bot',
    'student_message' => 'chat',
    'activity' => 'activities',
    'correction' => 'corrections',
    'voice' => 'practice',
    'diagnostic' => 'diagnostic',
    'profile' => 'profile',
];

$pageTitle = 'Histórico';
$pageSubtitle = 'Uma linha do tempo com conversas, atividades, correções e marcos de estudo.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="panel">
    <div class="panel-head"><div><h2>Linha do tempo</h2><p>Até 250 registros mais recentes.</p></div></div>
    <div class="filter-row filter-form">
        <?php
        $filters = [
            '' => 'Tudo',
            'teacher_message' => 'Emma',
            'student_message' => 'Mensagens',
            'activity' => 'Atividades',
            'correction' => 'Correções',
            'voice' => 'Áudios',
        ];
        foreach ($filters as $value => $label):
        ?>
            <a class="btn <?= $type === $value ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="<?= $value === '' ? '/portal/history.php' : '?type=' . urlencode($value) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$groups): ?>
        <div class="empty-state"><div class="empty-state-icon"><?= ui_icon('history') ?></div><h3>Nenhum registro neste filtro</h3><p>Comece uma prática para criar seu histórico.</p><a class="btn btn-primary btn-sm" href="/portal/practice.php">Praticar com Emma</a></div>
    <?php else: ?>
        <div class="history-groups">
            <?php foreach ($groups as $date => $items): ?>
                <section class="history-day">
                    <div class="history-date"><?= e((new DateTimeImmutable($date))->format('d/m/Y')) ?></div>
                    <div class="history-day-events">
                        <?php foreach ($items as $event):
                            $icon = $icons[$event['event_type']] ?? 'sparkles';
                        ?>
                            <article class="history-event">
                                <div class="history-event-icon"><?= ui_icon($icon) ?></div>
                                <div class="history-event-content">
                                    <div class="history-event-head"><strong><?= e((string)$event['title']) ?></strong><time><?= e((new DateTimeImmutable((string)$event['created_at']))->format('H:i')) ?></time></div>
                                    <?php if (trim((string)$event['description']) !== ''): ?><p><?= e(mb_strimwidth((string)$event['description'], 0, 320, '…')) ?></p><?php endif; ?>
                                    <?php if (trim((string)$event['meta']) !== ''): ?><span class="badge neutral"><?= e(ui_status_label((string)$event['meta'])) ?></span><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
