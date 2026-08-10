<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_login();

$pdo = db();
$q = trim($_GET['q'] ?? '');

$sql = "
SELECT s.id, s.name, s.phone, s.email, s.status,
       COALESCE(sp.overall_level,'A1') AS overall_level,
       COALESCE(sp.xp,0) AS xp,
       sp.last_study_at
FROM students s
LEFT JOIN student_profiles sp ON sp.student_id = s.id
";
$params = [];

if ($q !== '') {
    $sql .= " WHERE s.name ILIKE :q OR s.phone ILIKE :q OR s.email ILIKE :q";
    $params['q'] = "%{$q}%";
}

$sql .= " ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'Alunos';
require __DIR__ . '/../templates/header.php';
?>

<section class="panel">
    <form method="get" style="display:flex;gap:10px;margin-bottom:18px">
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nome, telefone ou e-mail">
        <button style="max-width:120px">Buscar</button>
    </form>

    <table>
        <thead><tr><th>Aluno</th><th>Telefone</th><th>Nível</th><th>XP</th><th>Último estudo</th></tr></thead>
        <tbody>
        <?php foreach ($students as $student): ?>
        <tr>
            <td><a href="/student.php?id=<?= urlencode($student['id']) ?>"><strong><?= htmlspecialchars($student['name']) ?></strong></a></td>
            <td><?= htmlspecialchars($student['phone'] ?? '-') ?></td>
            <td><span class="badge"><?= htmlspecialchars($student['overall_level']) ?></span></td>
            <td><?= (int)$student['xp'] ?></td>
            <td><?= $student['last_study_at'] ? date('d/m/Y H:i', strtotime($student['last_study_at'])) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
