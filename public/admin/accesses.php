<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/access.php';
require_once __DIR__ . '/../../src/ui.php';

require_admin();

$pdo = db();
$error = null;
$success = null;
$activationUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'provision_all') {
            $students = $pdo->query(<<<'SQL'
                SELECT s.id, s.name, s.phone, s.email
                FROM students s
                LEFT JOIN app_users u
                  ON u.student_id = s.id
                 AND u.role = 'student'
                WHERE u.id IS NULL
                ORDER BY s.created_at ASC
            SQL)->fetchAll();

            $created = 0;
            foreach ($students as $student) {
                ensure_student_portal_access(
                    $pdo,
                    (string)$student['id'],
                    (string)$student['name'],
                    (string)$student['phone'],
                    $student['email'] ?? null,
                    false,
                    false,
                    'admin_bulk'
                );
                $created++;
            }
            $success = $created === 1
                ? '1 acesso pendente foi criado.'
                : $created . ' acessos pendentes foram criados.';
        } elseif (in_array($action, ['provision', 'renew'], true)) {
            $studentId = trim((string)($_POST['student_id'] ?? ''));
            $stmt = $pdo->prepare('SELECT id, name, phone, email FROM students WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $studentId]);
            $student = $stmt->fetch();
            if (!$student) {
                throw new RuntimeException('Aluno não encontrado.');
            }

            $result = ensure_student_portal_access(
                $pdo,
                (string)$student['id'],
                (string)$student['name'],
                (string)$student['phone'],
                $student['email'] ?? null,
                true,
                true,
                'admin_manual'
            );
            $activationUrl = $result['activation_url'] ?? null;
            $success = $activationUrl
                ? 'Novo link de ativação gerado. Copie e envie ao aluno.'
                : 'O acesso do aluno já está ativo.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rows = $pdo->query(<<<'SQL'
    SELECT
        s.id AS student_id,
        s.name,
        s.phone,
        s.email,
        s.created_at AS student_created_at,
        u.id AS user_id,
        u.username,
        u.status AS user_status,
        u.activated_at,
        u.last_login_at
    FROM students s
    LEFT JOIN app_users u
      ON u.student_id = s.id
     AND u.role = 'student'
    ORDER BY s.created_at DESC
SQL)->fetchAll();

$pageTitle = 'Acessos dos alunos';
$pageSubtitle = 'Vincule o cadastro do WhatsApp ao portal sem duplicar o aluno.';
require __DIR__ . '/../../templates/header.php';
?>

<?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
<?php if ($activationUrl): ?>
<section class="panel">
    <div class="panel-head"><div><h2>Link de ativação</h2><p>O link é de uso único e expira em 7 dias.</p></div></div>
    <div class="form-row"><label>Copie e envie ao aluno</label><input value="<?= e($activationUrl) ?>" readonly onclick="this.select()"></div>
</section>
<?php endif; ?>

<section class="panel section-gap">
    <div class="panel-head">
        <div><h2>Alunos e usuários</h2><p>Cada usuário de aluno permanece vinculado ao mesmo UUID usado pela Emma.</p></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="provision_all">
            <button class="btn btn-secondary" type="submit">Criar acessos pendentes</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Aluno</th><th>Telefone</th><th>Usuário</th><th>Status</th><th>Último acesso</th><th>Ação</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?= e((string)$row['name']) ?></strong><br><small><?= e((string)($row['email'] ?? '')) ?></small></td>
                    <td><?= e((string)$row['phone']) ?></td>
                    <td><?= e((string)($row['username'] ?? 'Ainda não criado')) ?></td>
                    <td><span class="badge <?= ($row['user_status'] ?? '') === 'active' ? 'success' : 'warning' ?>"><?= e((string)($row['user_status'] ?? 'sem acesso')) ?></span></td>
                    <td><?= e(ui_date((string)($row['last_login_at'] ?? ''))) ?></td>
                    <td>
                        <?php if (($row['user_status'] ?? '') === 'active'): ?>
                            <span class="label">Ativo</span>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= $row['user_id'] ? 'renew' : 'provision' ?>">
                                <input type="hidden" name="student_id" value="<?= e((string)$row['student_id']) ?>">
                                <button class="btn btn-primary btn-sm" type="submit"><?= $row['user_id'] ? 'Gerar novo link' : 'Criar acesso' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
