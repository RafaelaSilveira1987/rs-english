<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/ui.php';

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $login = trim((string)($_POST['login'] ?? ''));
    if ($login !== '') {
        try {
            $phone = preg_replace('/\D+/', '', $login) ?: '';
            $stmt = db()->prepare(<<<'SQL'
                SELECT id
                FROM app_users
                WHERE status = 'active'
                  AND (
                    lower(username) = lower(:login_username)
                    OR lower(COALESCE(email, '')) = lower(:login_email)
                    OR (:phone_present <> '' AND regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone_value)
                  )
                LIMIT 1
            SQL);
            $stmt->execute(['login_username' => $login, 'login_email' => $login, 'phone_present' => $phone, 'phone_value' => $phone]);
            if ($id = $stmt->fetchColumn()) {
                generate_password_reset_token((string)$id);
            }
        } catch (Throwable $e) {
            // Mantém resposta neutra para não revelar contas existentes.
        }
    }
    $message = 'Solicitação registrada. Entre em contato com o administrador para receber o link.';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperar senha — RS English</title><link rel="icon" href="/assets/images/rs-english-mark-transparent.png" type="image/png"><link rel="stylesheet" href="/assets/css/app.css?v=16.0"></head><body class="login-body"><div class="login-page"><form class="login-box" method="post"><?= csrf_field() ?><h1>Recuperar senha</h1><?php if ($message): ?><div class="list-card"><strong><?= e($message) ?></strong></div><?php endif; ?><div class="form-row"><label>Usuário, e-mail ou telefone</label><input name="login" required autocomplete="username"></div><button class="btn btn-primary" style="width:100%">Solicitar</button><div style="text-align:center;margin-top:15px"><a class="label" href="/login.php">Voltar</a></div></form></div></body></html>
