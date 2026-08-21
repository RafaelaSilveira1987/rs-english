<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/access.php';
require_once __DIR__ . '/../../src/ui.php';

require_admin();

$pdo = db();
$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$error = null;
$success = null;
$actor = current_user();

function admin_load_account(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT
            u.*,
            s.name AS student_name,
            s.phone AS student_phone,
            s.email AS student_email
        FROM app_users u
        LEFT JOIN students s ON s.id = u.student_id
        WHERE u.id = :id
        LIMIT 1
    SQL);
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

$account = admin_load_account($pdo, $id);
if (!$account) {
    http_response_code(404);
    exit('Usuário não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = trim((string)($_POST['section'] ?? 'profile'));

    try {
        if ($section === 'profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = access_normalize_phone((string)($_POST['phone'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'active'));

            if ($name === '') {
                throw new RuntimeException('Informe o nome.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }
            if (!in_array($status, ['active', 'inactive', 'pending_activation'], true)) {
                throw new RuntimeException('Status inválido.');
            }
            if ((string)$account['role'] === 'student' && $phone === '') {
                throw new RuntimeException('O telefone é obrigatório para contas de aluno.');
            }
            if ($actor && (string)$actor['id'] === $id && $status !== 'active') {
                throw new RuntimeException('Você não pode desativar a conta que está usando.');
            }

            $normalizedUsername = access_validate_username($pdo, $username, $id);
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(<<<'SQL'
                UPDATE app_users
                SET name = :name,
                    username = :username_value,
                    username_changed_at = CASE WHEN username IS DISTINCT FROM :username_compare THEN NOW() ELSE username_changed_at END,
                    email = :email,
                    phone = :phone,
                    status = :status_value,
                    auth_version = CASE WHEN status IS DISTINCT FROM :status_compare THEN COALESCE(auth_version, 1) + 1 ELSE COALESCE(auth_version, 1) END,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING auth_version
            SQL);
            $stmt->execute([
                'name' => $name,
                'username_value' => $normalizedUsername,
                'username_compare' => $normalizedUsername,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'status_value' => $status,
                'status_compare' => $status,
                'id' => $id,
            ]);
            $authVersion = (int)$stmt->fetchColumn();

            if (!empty($account['student_id'])) {
                $pdo->prepare(<<<'SQL'
                    UPDATE students
                    SET name = :name,
                        email = :email,
                        phone = :phone,
                        updated_at = NOW()
                    WHERE id = :student_id
                SQL)->execute([
                    'name' => $name,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'student_id' => $account['student_id'],
                ]);
            }

            $pdo->commit();
            if ($actor && (string)$actor['id'] === $id) {
                $_SESSION['auth_version'] = $authVersion;
                forget_current_user_cache();
            }
            audit_log('user_access_updated', 'app_user', $id, [
                'username' => $normalizedUsername,
                'status' => $status,
            ]);
            $success = 'Dados de acesso atualizados.';
        } elseif ($section === 'password') {
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmation = (string)($_POST['new_password_confirmation'] ?? '');
            $mustChange = isset($_POST['must_change_password']);

            if ($newPassword !== $confirmation) {
                throw new RuntimeException('As novas senhas não são iguais.');
            }

            $actorId = $actor ? (string)$actor['id'] : null;
            $newVersion = access_set_password(
                $pdo,
                $id,
                $newPassword,
                $mustChange,
                $actorId,
                true
            );

            if ($actorId === $id) {
                $_SESSION['auth_version'] = $newVersion;
                forget_current_user_cache();
            }
            $success = $mustChange
                ? 'Senha temporária definida. O usuário deverá alterá-la no próximo login.'
                : 'Senha redefinida com sucesso.';
        } else {
            throw new RuntimeException('Seção inválida.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = str_contains($e->getMessage(), 'duplicate key')
            ? 'Usuário, e-mail ou telefone já está sendo utilizado por outra conta.'
            : $e->getMessage();
    }

    $account = admin_load_account($pdo, $id) ?: $account;
}

$pageTitle = 'Editar acesso';
$pageSubtitle = 'Gerencie identificação, nome de usuário, status e senha.';
require __DIR__ . '/../../templates/header.php';
?>

<?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

<section class="student-head">
    <div style="display:flex;align-items:center;gap:17px">
        <div class="avatar avatar-lg"><?= e(ui_initials((string)$account['name'])) ?></div>
        <div>
            <div class="list-meta">
                <span class="badge"><?= e(ui_role_label((string)$account['role'])) ?></span>
                <span class="badge <?= $account['status'] === 'active' ? 'success' : 'warning' ?>"><?= e(ui_status_label((string)$account['status'])) ?></span>
            </div>
            <h2><?= e((string)$account['name']) ?></h2>
            <div class="label">Usuário: <?= e((string)$account['username']) ?></div>
        </div>
    </div>
    <div class="form-actions">
        <?php if (!empty($account['student_id'])): ?>
            <a class="btn btn-secondary btn-sm" href="/student.php?id=<?= e((string)$account['student_id']) ?>">Ver ficha do aluno</a>
        <?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="/admin/users.php">Voltar</a>
    </div>
</section>

<div class="grid-2 equal section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Identificação e login</h2><p>O aluno poderá entrar com usuário, e-mail ou telefone.</p></div></div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="section" value="profile">
            <div class="form-row"><label>Nome</label><input name="name" value="<?= e((string)$account['name']) ?>" required></div>
            <div class="form-row"><label>Nome de usuário</label><input name="username" value="<?= e((string)$account['username']) ?>" required autocomplete="username"><small class="form-help">Use letras, números, ponto, hífen ou sublinhado. O usuário não pode ser apenas numérico.</small></div>
            <div class="form-row"><label>E-mail</label><input type="email" name="email" value="<?= e((string)($account['email'] ?? '')) ?>"></div>
            <div class="form-row"><label>Telefone</label><input name="phone" value="<?= e((string)($account['phone'] ?? '')) ?>" <?= $account['role'] === 'student' ? 'required' : '' ?>></div>
            <div class="form-row"><label>Status</label><select name="status"><option value="active" <?= $account['status']==='active'?'selected':'' ?>>Ativo</option><option value="inactive" <?= $account['status']==='inactive'?'selected':'' ?>>Inativo</option><option value="pending_activation" <?= $account['status']==='pending_activation'?'selected':'' ?>>Aguardando ativação</option></select></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar acesso</button></div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Redefinir senha</h2><p>O administrador não precisa conhecer a senha atual do usuário.</p></div></div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="section" value="password">
            <div class="form-row"><label>Nova senha</label><input type="password" name="new_password" required autocomplete="new-password"></div>
            <div class="form-row"><label>Confirmar nova senha</label><input type="password" name="new_password_confirmation" required autocomplete="new-password"></div>
            <small class="form-help">Mínimo 8 caracteres, com letra maiúscula, minúscula e número.</small>
            <label class="toggle-row" style="margin-top:16px"><input type="checkbox" name="must_change_password"><span><strong>Exigir troca no próximo login</strong><small>Use quando estiver entregando uma senha temporária ao aluno.</small></span></label>
            <div class="form-actions"><button class="btn btn-secondary" type="submit">Redefinir senha</button></div>
        </form>

        <div class="info-grid" style="margin-top:20px">
            <div class="info-item"><span>Último login</span><strong><?= e(ui_date((string)($account['last_login_at'] ?? ''))) ?></strong></div>
            <div class="info-item"><span>Senha alterada</span><strong><?= e(ui_date((string)($account['password_changed_at'] ?? ''))) ?></strong></div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
