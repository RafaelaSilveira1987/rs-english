<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_login();

$pdo = db();

$stats = [
    'students' => (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn(),
    'sessions' => (int)$pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn(),
    'errors' => (int)$pdo->query("SELECT COUNT(*) FROM student_errors WHERE status = 'learning'")->fetchColumn(),
    'words' => (int)$pdo->query("SELECT COUNT(*) FROM student_vocabulary WHERE status IN ('learning','mastered')")->fetchColumn(),
];

$recent = $pdo->query("
    SELECT
        s.id,
        s.name,
        s.phone,
        COALESCE(sp.overall_level, 'A1') AS overall_level,
        sp.last_study_at,
        COALESCE(sp.grammar_score,0) AS grammar_score,
        COALESCE(sp.vocabulary_score,0) AS vocabulary_score
    FROM students s
    LEFT JOIN student_profiles sp ON sp.student_id = s.id
    WHERE s.status = 'active'
    ORDER BY COALESCE(sp.last_study_at, s.created_at) DESC
    LIMIT 10
")->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/../templates/header.php';
?>

<section class="cards">
    <div class="card"><div class="label">Alunos ativos</div><div class="metric"><?= $stats['students'] ?></div></div>
    <div class="card"><div class="label">Sessões</div><div class="metric"><?= $stats['sessions'] ?></div></div>
    <div class="card"><div class="label">Erros em revisão</div><div class="metric"><?= $stats['errors'] ?></div></div>
    <div class="card"><div class="label">Palavras acompanhadas</div><div class="metric"><?= $stats['words'] ?></div></div>
</section>

<section class="panel">
    <h2>Alunos recentes</h2>
    <table>
        <thead>
        <tr>
            <th>Aluno</th>
            <th>Nível</th>
            <th>Grammar</th>
            <th>Vocabulary</th>
            <th>Último estudo</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $student): ?>
            <tr>
                <td><a href="/student.php?id=<?= urlencode($student['id']) ?>"><strong><?= htmlspecialchars($student['name']) ?></strong></a><br><span class="label"><?= htmlspecialchars($student['phone'] ?? '') ?></span></td>
                <td><span class="badge"><?= htmlspecialchars($student['overall_level']) ?></span></td>
                <td><?= number_format((float)$student['grammar_score'], 0) ?>%</td>
                <td><?= number_format((float)$student['vocabulary_score'], 0) ?>%</td>
                <td><?= $student['last_study_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($student['last_study_at']))) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
