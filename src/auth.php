<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['rs_english_admin']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function attempt_login(string $user, string $password): bool
{
    $validUser = env('ADMIN_USER', 'admin');
    $validPassword = env('ADMIN_PASSWORD');

    if (!$validPassword) {
        return false;
    }

    if (hash_equals($validUser, $user) && hash_equals($validPassword, $password)) {
        session_regenerate_id(true);
        $_SESSION['rs_english_admin'] = $user;
        return true;
    }

    return false;
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
