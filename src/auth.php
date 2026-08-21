<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function forget_current_user_cache(): void
{
    unset($GLOBALS['rs_current_user_cache']);
}

function clear_database_user_session(): void
{
    unset($_SESSION['user_id'], $_SESSION['auth_version']);
    forget_current_user_cache();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    if (array_key_exists('rs_current_user_cache', $GLOBALS)) {
        return $GLOBALS['rs_current_user_cache'];
    }

    try {
        $stmt = db()->prepare(<<<'SQL'
            SELECT
                id, organization_id, student_id,
                name, email, phone, username, role, status,
                COALESCE(must_change_password, FALSE) AS must_change_password,
                COALESCE(auth_version, 1) AS auth_version,
                last_login_at, password_changed_at
            FROM app_users
            WHERE id = :id
            LIMIT 1
        SQL);
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;

        if (!$user || (string)$user['status'] !== 'active') {
            clear_database_user_session();
            return null;
        }

        $databaseVersion = max(1, (int)$user['auth_version']);
        if (isset($_SESSION['auth_version']) && (int)$_SESSION['auth_version'] !== $databaseVersion) {
            clear_database_user_session();
            return null;
        }

        $_SESSION['auth_version'] = $databaseVersion;
        $GLOBALS['rs_current_user_cache'] = $user;

        return $user;
    } catch (Throwable $e) {
        // Compatibilidade durante a janela entre o deploy e a migration 034.
        try {
            $stmt = db()->prepare(<<<'SQL'
                SELECT
                    id, organization_id, student_id,
                    name, email, phone, username, role, status,
                    COALESCE(must_change_password, FALSE) AS must_change_password,
                    1 AS auth_version,
                    last_login_at, password_changed_at
                FROM app_users
                WHERE id = :id
                LIMIT 1
            SQL);
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
            if (!$user || (string)$user['status'] !== 'active') {
                clear_database_user_session();
                return null;
            }
            $_SESSION['auth_version'] = 1;
            $GLOBALS['rs_current_user_cache'] = $user;
            return $user;
        } catch (Throwable $ignored) {
            return null;
        }
    }
}

function is_logged_in(): bool
{
    return current_user() !== null || !empty($_SESSION['legacy_admin']);
}

function is_admin(): bool
{
    if (!empty($_SESSION['legacy_admin'])) {
        return true;
    }

    $user = current_user();
    return $user && in_array($user['role'], ['admin', 'owner'], true);
}

function is_teacher(): bool
{
    if (is_admin()) {
        return true;
    }

    $user = current_user();
    return $user && $user['role'] === 'teacher';
}

function is_student(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'student';
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location:/login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

function require_teacher_or_admin(): void
{
    require_login();

    if (!is_teacher()) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

function require_student(): array
{
    require_login();
    $user = current_user();

    if (!$user || $user['role'] !== 'student' || !$user['student_id']) {
        http_response_code(403);
        exit('Área exclusiva para alunos.');
    }

    return $user;
}

function attempt_login(string $login, string $password): bool
{
    $login = trim($login);

    try {
        $phoneLogin = preg_replace('/\D+/', '', $login) ?: '';
        $stmt = db()->prepare(<<<'SQL'
            SELECT *
            FROM app_users
            WHERE status = 'active'
              AND (
                  lower(username) = lower(:login_username)
                  OR lower(COALESCE(email, '')) = lower(:login_email)
                  OR (
                      :phone_present <> ''
                      AND regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone_value
                  )
              )
            LIMIT 1
        SQL);
        $stmt->execute(['login_username' => $login, 'login_email' => $login, 'phone_present' => $phoneLogin, 'phone_value' => $phoneLogin]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['auth_version'] = max(1, (int)($user['auth_version'] ?? 1));
            unset($_SESSION['legacy_admin']);
            forget_current_user_cache();

            try {
                db()->prepare(<<<'SQL'
                    UPDATE app_users
                    SET last_login_at = NOW(),
                        first_access_at = COALESCE(first_access_at, NOW()),
                        failed_login_count = 0,
                        locked_until = NULL
                    WHERE id = :id
                SQL)->execute(['id' => $user['id']]);
            } catch (Throwable $ignored) {
                db()->prepare('UPDATE app_users SET last_login_at = NOW() WHERE id = :id')
                    ->execute(['id' => $user['id']]);
            }

            if (function_exists('record_login_attempt')) {
                record_login_attempt($login, true);
            }

            return true;
        }
    } catch (Throwable $e) {
        // Fallback de administrador legado abaixo.
    }

    $legacyUser = (string)env('ADMIN_USER', 'admin');
    $legacyPassword = env('ADMIN_PASSWORD');

    if (
        $legacyPassword
        && hash_equals($legacyUser, $login)
        && hash_equals((string)$legacyPassword, $password)
    ) {
        session_regenerate_id(true);
        $_SESSION['legacy_admin'] = $legacyUser;
        clear_database_user_session();
        $_SESSION['legacy_admin'] = $legacyUser;
        return true;
    }

    if (function_exists('record_login_attempt')) {
        record_login_attempt($login, false);
    }

    return false;
}

function account_requires_activation(string $login): bool
{
    $login = trim($login);
    if ($login === '') {
        return false;
    }

    try {
        $phoneLogin = preg_replace('/\D+/', '', $login) ?: '';
        $stmt = db()->prepare(<<<'SQL'
            SELECT 1
            FROM app_users
            WHERE status = 'pending_activation'
              AND (
                  lower(username) = lower(:login_username)
                  OR lower(COALESCE(email, '')) = lower(:login_email)
                  OR (
                      :phone_present <> ''
                      AND regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone_value
                  )
              )
            LIMIT 1
        SQL);
        $stmt->execute(['login_username' => $login, 'login_email' => $login, 'phone_present' => $phoneLogin, 'phone_value' => $phoneLogin]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    forget_current_user_cache();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function post_login_redirect(): string
{
    $user = current_user();
    if ($user && !empty($user['must_change_password'])) {
        return '/change-password.php?required=1';
    }

    return is_student() ? '/portal/index.php' : '/index.php';
}
