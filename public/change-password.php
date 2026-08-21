<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/access.php';
require_once __DIR__ . '/../src/ui.php';

require_login();
$user = current_user();

if (!$user) {
    http_response_code(400);
    exit('O acesso administrativo legado é configurado no EasyPanel. Crie um administrador em Usuários para alterar a senha pela plataforma.');
}

$error = null;
$success = null;
$required = isset($_GET['required']) || !empty($user['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmation = (string)($_POST['new_password_confirmation'] ?? '');

    try {
        $stmt = db()->prepare('SELECT password_hash FROM app_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $user['id']]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Senha atual incorreta.');
        }
        if ($newPassword !== $confirmation) {
            throw new RuntimeException('As novas senhas não são iguais.');
        }

        $newAuthVersion = access_set_password(db(), (string)$user['id'], $newPassword, false, null);
        $_SESSION['auth_version'] = $newAuthVersion;
        forget_current_user_cache();
        $success = 'Senha alterada com sucesso.';
        $required = false;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = $required ? 'Crie uma nova senha' : 'Alterar senha';
$pageSubtitle = $required
    ? 'Por segurança, defina uma senha pessoal antes de continuar.'
    : 'Atualize sua senha de acesso à plataforma.';
require __DIR__ . '/../templates/header.php';
?>

<?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

<section class="panel" style="max-width:720px">
    <div class="panel-head">
        <div>
            <h2><?= $required ? 'Defina sua senha definitiva' : 'Segurança da conta' ?></h2>
            <p>Use no mínimo 8 caracteres, com letra maiúscula, minúscula e número.</p>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="current_password">Senha atual</label>
            <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="grid-2 form-grid-2">
            <div class="form-row">
                <label for="new_password">Nova senha</label>
                <input id="new_password" type="password" name="new_password" required autocomplete="new-password">
            </div>
            <div class="form-row">
                <label for="new_password_confirmation">Confirmar nova senha</label>
                <input id="new_password_confirmation" type="password" name="new_password_confirmation" required autocomplete="new-password">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Salvar nova senha</button>
            <?php if ($success): ?>
                <a class="btn btn-secondary" href="<?= e(is_student() ? '/portal/index.php' : '/index.php') ?>">Continuar</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
