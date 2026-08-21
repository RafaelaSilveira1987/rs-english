<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/access.php';
require_once __DIR__ . '/../../src/ui.php';

require_admin();

$pdo = db();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? 'create'));
        if ($action !== 'create') {
            throw new RuntimeException('Ação inválida.');
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = access_normalize_phone((string)($_POST['phone'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $role = trim((string)($_POST['role'] ?? 'student'));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '') {
            throw new RuntimeException('Informe o nome.');
        }
        if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
            throw new RuntimeException('Perfil inválido.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        if ($role === 'student' && $phone === '') {
            throw new RuntimeException('Telefone é obrigatório para aluno.');
        }
        if (!password_is_strong($password)) {
            throw new RuntimeException('A senha deve ter 8 caracteres, com maiúscula, minúscula e número.');
        }

        $username = access_validate_username($pdo, $username);
        $studentId = null;
        $pdo->beginTransaction();

        if ($role === 'student') {
            $find = $pdo->prepare(<<<'SQL'
                SELECT id
                FROM students
                WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
                LIMIT 1
            SQL);
            $find->execute(['phone' => $phone]);
            $studentId = $find->fetchColumn() ?: null;

            if ($studentId) {
                $linked = $pdo->prepare("SELECT 1 FROM app_users WHERE student_id = :student_id AND role = 'student' LIMIT 1");
                $linked->execute(['student_id' => $studentId]);
                if ($linked->fetchColumn()) {
                    throw new RuntimeException('Este aluno já possui acesso. Edite o usuário existente.');
                }

                $pdo->prepare('UPDATE students SET name = :name, email = :email, phone = :phone, updated_at = NOW() WHERE id = :id')
                    ->execute([
                        'name' => $name,
                        'email' => $email !== '' ? $email : null,
                        'phone' => $phone,
                        'id' => $studentId,
                    ]);
            } else {
                $create = $pdo->prepare(<<<'SQL'
                    INSERT INTO students(name, phone, email)
                    VALUES(:name, :phone, :email)
                    RETURNING id
                SQL);
                $create->execute([
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email !== '' ? $email : null,
                ]);
                $studentId = $create->fetchColumn();

                $pdo->prepare(<<<'SQL'
                    INSERT INTO student_profiles(
                        student_id, overall_level, estimated_level, goal,
                        correction_mode, diagnostic_status, diagnostic_step
                    ) VALUES(
                        :id, 'PRE-A1', 'PRE-A1', 'Aprender inglês',
                        'balanced', 'pending', 0
                    )
                    ON CONFLICT(student_id) DO NOTHING
                SQL)->execute(['id' => $studentId]);
            }
        }

        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO app_users(
                student_id, name, email, phone, username, password_hash,
                role, status, must_change_password, access_origin, auth_version
            ) VALUES(
                :student_id, :name, :email, :phone, :username, :password_hash,
                :role, 'active', FALSE, 'admin_manual', 1
            )
            RETURNING id
        SQL);
        $stmt->execute([
            'student_id' => $studentId,
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        $userId = (string)$stmt->fetchColumn();

        $pdo->commit();
        audit_log('user_created', 'app_user', $userId, ['role' => $role]);
        $success = 'Usuário criado com sucesso.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = str_contains($e->getMessage(), 'duplicate key')
            ? 'Usuário, e-mail ou telefone já está sendo utilizado.'
            : $e->getMessage();
    }
}

$rows = $pdo->query(<<<'SQL'
    SELECT
        u.id, u.name, u.email, u.phone, u.username, u.role, u.status,
        u.last_login_at, u.must_change_password, u.student_id
    FROM app_users u
    ORDER BY u.created_at DESC
SQL)->fetchAll();

$pageTitle = 'Usuários';
$pageSubtitle = 'Crie contas e edite nome de usuário, status e senha de acesso.';
require __DIR__ . '/../../templates/header.php';
?>

<?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

<?php if (!empty($_SESSION['legacy_admin'])): ?>
<section class="attention-summary section-gap-sm">
    <div>
        <span class="badge warning">Administrador legado</span>
        <h3>Crie um administrador da plataforma</h3>
        <p>A senha definida em ADMIN_PASSWORD no EasyPanel não pode ser alterada pelo PHP. Crie um usuário com perfil Administrador para gerenciar a própria senha pelo painel.</p>
    </div>
</section>
<?php endif; ?>

<div class="grid-2 equal">
<section class="panel">
    <div class="panel-head"><div><h2>Novo usuário</h2><p>Para alunos, o cadastro fica vinculado ao mesmo registro pedagógico.</p></div></div>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-row"><label>Nome</label><input name="name" required></div>
        <div class="grid-2 form-grid-2">
            <div class="form-row"><label>Nome de usuário</label><input name="username" required placeholder="ex.: rafaela.silveira"><small class="form-help">Não use somente o telefone.</small></div>
            <div class="form-row"><label>Perfil</label><select name="role"><option value="student">Aluno</option><option value="teacher">Professor</option><option value="admin">Administrador</option></select></div>
        </div>
        <div class="form-row"><label>E-mail</label><input type="email" name="email"></div>
        <div class="form-row"><label>Telefone</label><input name="phone" placeholder="5532..."><small class="form-help">Obrigatório para aluno e usado na integração com o WhatsApp.</small></div>
        <div class="form-row"><label>Senha inicial</label><input type="password" name="password" required autocomplete="new-password"><small class="form-help">Mínimo 8 caracteres, com maiúscula, minúscula e número.</small></div>
        <button class="btn btn-primary" type="submit">Criar usuário</button>
    </form>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Gestão de acesso</h2><p>O botão Editar permite trocar usuário, dados, status e senha.</p></div></div>
    <div class="list-card"><strong>Aluno</strong><p>Acessa o próprio progresso e pode alterar usuário e senha no Meu perfil.</p></div>
    <div class="list-card"><strong>Professor</strong><p>Acompanha alunos, atividades, conteúdos e relatórios.</p></div>
    <div class="list-card"><strong>Administrador</strong><p>Gerencia contas e pode redefinir senhas sem conhecer a senha anterior.</p></div>
</section>
</div>

<section class="panel section-gap">
    <div class="panel-head"><div><h2>Usuários cadastrados</h2><p><?= count($rows) ?> conta(s) cadastrada(s).</p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Telefone</th><th>Status</th><th>Último login</th><th>Ação</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $account): ?>
                <tr>
                    <td><strong><?= e((string)$account['name']) ?></strong><div class="label"><?= e((string)($account['email'] ?? '')) ?></div></td>
                    <td><?= e((string)($account['username'] ?? '—')) ?><?php if (!empty($account['must_change_password'])): ?><div class="label">troca de senha pendente</div><?php endif; ?></td>
                    <td><span class="badge"><?= e(ui_role_label((string)$account['role'])) ?></span></td>
                    <td><?= e((string)($account['phone'] ?? '—')) ?></td>
                    <td><span class="badge <?= $account['status'] === 'active' ? 'success' : 'warning' ?>"><?= e(ui_status_label((string)$account['status'])) ?></span></td>
                    <td><?= e(ui_date((string)($account['last_login_at'] ?? ''))) ?></td>
                    <td><a class="btn btn-secondary btn-sm" href="/admin/user-edit.php?id=<?= e((string)$account['id']) ?>">Editar acesso</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
