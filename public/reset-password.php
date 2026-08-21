<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/access.php';
require_once __DIR__ . '/../src/ui.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$record = validate_password_reset_token($token);
$error = null;
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $record = validate_password_reset_token($token);
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');

    try {
        if (!$record) throw new RuntimeException('Link expirado ou utilizado.');
        if ($password !== $confirmation) throw new RuntimeException('As senhas não são iguais.');

        $pdo = db();
        $pdo->beginTransaction();
        access_set_password($pdo, (string)$record['user_id'], $password, false, null, false);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => $record['token_id']]);
        $pdo->commit();
        audit_log('password_reset_completed', 'app_user', (string)$record['user_id']);
        $ok = true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nova senha — RS English</title><link rel="icon" href="/assets/images/rs-english-mark-transparent.png" type="image/png"><link rel="stylesheet" href="/assets/css/app.css?v=16.0"></head><body class="login-body"><div class="login-page"><div class="login-box"><h1>Nova senha</h1><?php if ($ok): ?><div class="alert success">Senha atualizada. As sessões anteriores foram encerradas.</div><a class="btn btn-primary" style="width:100%" href="/login.php">Entrar</a><?php elseif (!$record): ?><div class="error">Link expirado ou utilizado.</div><?php else: ?><?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><div class="form-row"><label>Nova senha</label><input type="password" name="password" required autocomplete="new-password"></div><div class="form-row"><label>Confirmar</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div><small class="form-help">Mínimo 8 caracteres, com maiúscula, minúscula e número.</small><button class="btn btn-primary" style="width:100%">Salvar</button></form><?php endif; ?></div></div></body></html>
