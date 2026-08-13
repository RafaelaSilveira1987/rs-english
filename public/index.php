<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();

$stats=[
    'students'=>(int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn(),
    'sessions'=>(int)$pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
    'activities'=>(int)$pdo->query("SELECT COUNT(*) FROM student_activities WHERE status='pending'")->fetchColumn(),
    'reports'=>(int)$pdo->query("SELECT COUNT(*) FROM weekly_reports")->fetchColumn(),
    'errors_due'=>(int)$pdo->query("
        SELECT COUNT(*)
        FROM student_errors
        WHERE status='learning'
          AND (next_review_at IS NULL OR next_review_at<=NOW())
    ")->fetchColumn(),
    'words_due'=>(int)$pdo->query("
        SELECT COUNT(*)
        FROM student_vocabulary
        WHERE status IN ('learning','review')
          AND (next_review_at IS NULL OR next_review_at<=NOW())
    ")->fetchColumn(),
    'sources'=>(int)$pdo->query("
        SELECT COUNT(*)
        FROM knowledge_sources
        WHERE active=true
    ")->fetchColumn()
];

$recent=$pdo->query("
SELECT
    s.id,s.name,s.phone,
    COALESCE(sp.overall_level,'A1') overall_level,
    COALESCE(sp.xp,0) xp,
    COALESCE(sp.grammar_score,0) grammar_score,
    COALESCE(sp.vocabulary_score,0) vocabulary_score,
    sp.last_study_at,
    (
        SELECT COUNT(*)
        FROM student_errors se
        WHERE se.student_id=s.id
          AND se.status='learning'
          AND (se.next_review_at IS NULL OR se.next_review_at<=NOW())
    ) errors_due,
    (
        SELECT COUNT(*)
        FROM student_vocabulary sv
        WHERE sv.student_id=s.id
          AND sv.status IN ('learning','review')
          AND (sv.next_review_at IS NULL OR sv.next_review_at<=NOW())
    ) vocab_due
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id=s.id
WHERE s.status='active'
ORDER BY COALESCE(sp.last_study_at,s.created_at) DESC
LIMIT 12
")->fetchAll();

$pageTitle='Dashboard';
require __DIR__.'/../templates/header.php';
?>

<section class="cards">
    <div class="card">
        <div class="label">Alunos ativos</div>
        <div class="metric"><?= $stats['students'] ?></div>
        <div class="metric-sub">Base atual da plataforma</div>
    </div>

    <div class="card">
        <div class="label">Sessões realizadas</div>
        <div class="metric"><?= $stats['sessions'] ?></div>
        <div class="metric-sub">Conversas e avaliações registradas</div>
    </div>

    <div class="card">
        <div class="label">Atividades pendentes</div>
        <div class="metric"><?= $stats['activities'] ?></div>
        <div class="metric-sub">Próximos exercícios dos alunos</div>
    </div>

    <div class="card">
        <div class="label">Fontes de conteúdo</div>
        <div class="metric"><?= $stats['sources'] ?></div>
        <div class="metric-sub">Biblioteca e RAG</div>
    </div>
</section>

<section class="cards">
    <div class="card">
        <div class="label">Revisões gramaticais</div>
        <div class="metric"><?= $stats['errors_due'] ?></div>
    </div>

    <div class="card">
        <div class="label">Palavras para revisar</div>
        <div class="metric"><?= $stats['words_due'] ?></div>
    </div>

    <div class="card">
        <div class="label">Relatórios semanais</div>
        <div class="metric"><?= $stats['reports'] ?></div>
    </div>

    <div class="card">
        <div class="label">Status</div>
        <div class="metric" style="font-size:21px">Operacional</div>
        <div class="metric-sub">Web disponível mesmo sem WhatsApp</div>
    </div>
</section>

<section class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:8px">
        <div>
            <h2 style="margin-bottom:4px">Alunos recentes</h2>
            <div class="label">Evolução e pendências.</div>
        </div>
        <a class="btn btn-secondary" href="/students.php">Ver todos</a>
    </div>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Aluno</th>
            <th>Nível</th>
            <th>Grammar</th>
            <th>Vocabulary</th>
            <th>Revisões</th>
            <th>XP</th>
            <th>Último estudo</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($recent as $student): ?>
        <tr>
            <td>
                <a href="/student.php?id=<?= urlencode($student['id']) ?>">
                    <strong><?= htmlspecialchars($student['name']) ?></strong>
                </a>
                <div class="label"><?= htmlspecialchars($student['phone'] ?? '') ?></div>
            </td>
            <td><span class="badge"><?= htmlspecialchars($student['overall_level']) ?></span></td>
            <td><?= number_format((float)$student['grammar_score'],0) ?>%</td>
            <td><?= number_format((float)$student['vocabulary_score'],0) ?>%</td>
            <td>
                <span class="badge warning">
                    <?= (int)$student['errors_due']+(int)$student['vocab_due'] ?> pend.
                </span>
            </td>
            <td><strong><?= (int)$student['xp'] ?></strong></td>
            <td>
                <?= $student['last_study_at']
                    ? date('d/m/Y H:i',strtotime($student['last_study_at']))
                    : '-' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
