<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    try {
        $stmt = db()->prepare("
            SELECT
                id,organization_id,student_id,
                name,email,phone,username,role,status
            FROM app_users
            WHERE id=:id
            LIMIT 1
        ");

        $stmt->execute(['id'=>$_SESSION['user_id']]);

        $cache = $stmt->fetch() ?: null;

        return $cache;
    } catch (Throwable $e) {
        return null;
    }
}

function is_logged_in(): bool
{
    return current_user() !== null || !empty($_SESSION['legacy_admin']);
}

function is_admin(): bool
{
    if (!empty($_SESSION['legacy_admin'])) return true;

    $user=current_user();

    return $user && in_array($user['role'],['admin','owner'],true);
}

function is_teacher(): bool
{
    if (is_admin()) return true;

    $user=current_user();

    return $user && $user['role']==='teacher';
}

function is_student(): bool
{
    $user=current_user();

    return $user && $user['role']==='student';
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

    $user=current_user();

    if (!$user || $user['role']!=='student' || !$user['student_id']) {
        http_response_code(403);
        exit('Área exclusiva para alunos.');
    }

    return $user;
}

function attempt_login(string $login,string $password): bool
{
    $login=trim($login);

    try {
        $stmt=db()->prepare("
            SELECT *
            FROM app_users
            WHERE status='active'
              AND (
                  username=:login
                  OR email=:login
                  OR phone=:login
              )
            LIMIT 1
        ");

        $stmt->execute(['login'=>$login]);
        $user=$stmt->fetch();

        if ($user && password_verify($password,$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']=$user['id'];
            unset($_SESSION['legacy_admin']);

            db()->prepare("
                UPDATE app_users
                SET last_login_at=NOW()
                WHERE id=:id
            ")->execute(['id'=>$user['id']]);

            return true;
        }
    } catch (Throwable $e) {
        // fallback abaixo
    }

    $legacyUser=env('ADMIN_USER','admin');
    $legacyPassword=env('ADMIN_PASSWORD');

    if (
        $legacyPassword &&
        hash_equals($legacyUser,$login) &&
        hash_equals($legacyPassword,$password)
    ) {
        session_regenerate_id(true);

        $_SESSION['legacy_admin']=$legacyUser;
        unset($_SESSION['user_id']);

        return true;
    }

    return false;
}

function logout_user(): void
{
    $_SESSION=[];

    if (ini_get('session.use_cookies')) {
        $params=session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time()-42000,
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
    return is_student() ? '/portal/index.php' : '/index.php';
}
