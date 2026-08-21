<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function access_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?: '';
}

function access_base_url(): string
{
    return rtrim((string)env('APP_URL', 'https://rsenglish.rsautomacaodigital.cloud'), '/');
}

function access_normalize_username(string $username): string
{
    $username = trim(function_exists('mb_strtolower') ? mb_strtolower($username) : strtolower($username));

    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $username);
        if (is_string($converted)) {
            $username = $converted;
        }
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $username);
        if (is_string($converted) && $converted !== '') {
            $username = function_exists('mb_strtolower') ? mb_strtolower($converted) : strtolower($converted);
        }
    }

    $username = preg_replace('/\s+/', '.', $username) ?: '';
    $username = preg_replace('/[^a-z0-9._-]+/', '', $username) ?: '';
    $username = preg_replace('/[._-]{2,}/', '.', $username) ?: '';

    return trim($username, '._-');
}

function access_username_exists(PDO $pdo, string $username, ?string $excludeUserId = null): bool
{
    $sql = 'SELECT 1 FROM app_users WHERE lower(username) = lower(:username)';
    $params = ['username' => $username];

    if ($excludeUserId !== null && $excludeUserId !== '') {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeUserId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

function access_validate_username(
    PDO $pdo,
    string $username,
    ?string $excludeUserId = null,
    bool $allowNumericOnly = false
): string {
    $normalized = access_normalize_username($username);

    if (strlen($normalized) < 4 || strlen($normalized) > 40) {
        throw new RuntimeException('O usuário deve ter entre 4 e 40 caracteres.');
    }

    if (!preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/', $normalized)) {
        throw new RuntimeException('Use somente letras, números, ponto, hífen ou sublinhado.');
    }

    if (!$allowNumericOnly && !preg_match('/[a-z]/', $normalized)) {
        throw new RuntimeException('O usuário precisa conter pelo menos uma letra e não pode ser apenas o telefone.');
    }

    if (in_array($normalized, ['root', 'system', 'sistema', 'null', 'undefined'], true)) {
        throw new RuntimeException('Este nome de usuário é reservado.');
    }

    if (access_username_exists($pdo, $normalized, $excludeUserId)) {
        throw new RuntimeException('Este nome de usuário já está sendo usado.');
    }

    return $normalized;
}

function access_username_base_from_name(string $name, string $phone = ''): string
{
    $base = access_normalize_username($name);
    $parts = array_values(array_filter(explode('.', $base)));

    if (count($parts) > 2) {
        $base = $parts[0] . '.' . end($parts);
    }

    if (strlen($base) < 4) {
        $suffix = $phone !== '' ? substr($phone, -4) : bin2hex(random_bytes(2));
        $base = trim($base . '.' . $suffix, '.');
    }

    if (!preg_match('/[a-z]/', $base)) {
        $base = 'aluno.' . ($phone !== '' ? substr($phone, -6) : bin2hex(random_bytes(3)));
    }

    return substr($base, 0, 34);
}

function access_unique_username(PDO $pdo, string $name, string $phone = ''): string
{
    $base = access_username_base_from_name($name, $phone);
    $candidate = $base;
    $suffix = 1;

    while (access_username_exists($pdo, $candidate)) {
        $suffix++;
        $candidate = substr($base, 0, max(1, 40 - strlen((string)$suffix) - 1)) . '.' . $suffix;
    }

    return $candidate;
}

function access_update_username(PDO $pdo, string $userId, string $username, string $changedBy = 'self'): string
{
    $normalized = access_validate_username($pdo, $username, $userId);

    $stmt = $pdo->prepare(<<<'SQL'
        UPDATE app_users
        SET username = :username,
            username_changed_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    SQL);
    $stmt->execute(['username' => $normalized, 'id' => $userId]);

    if (function_exists('audit_log')) {
        audit_log('username_changed', 'app_user', $userId, [
            'changed_by' => $changedBy,
            'username' => $normalized,
        ]);
    }

    return $normalized;
}

function access_set_password(
    PDO $pdo,
    string $userId,
    string $password,
    bool $mustChangePassword = false,
    ?string $resetByUserId = null,
    bool $adminReset = false
): int {
    if (!function_exists('password_is_strong') || !password_is_strong($password)) {
        throw new RuntimeException('Use pelo menos 8 caracteres, com maiúscula, minúscula e número.');
    }

    $stmt = $pdo->prepare(<<<'SQL'
        UPDATE app_users
        SET password_hash = :password_hash,
            password_changed_at = NOW(),
            password_reset_at = CASE WHEN CAST(:admin_reset AS boolean) THEN NOW() ELSE password_reset_at END,
            password_reset_by = :reset_by,
            must_change_password = CAST(:must_change AS boolean),
            failed_login_count = 0,
            locked_until = NULL,
            auth_version = COALESCE(auth_version, 1) + 1,
            updated_at = NOW()
        WHERE id = :id
        RETURNING auth_version
    SQL);
    $stmt->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'reset_by' => $resetByUserId,
        'admin_reset' => $adminReset ? 'true' : 'false',
        'must_change' => $mustChangePassword ? 'true' : 'false',
        'id' => $userId,
    ]);

    $version = (int)$stmt->fetchColumn();
    if ($version < 1) {
        throw new RuntimeException('Usuário não encontrado para alteração da senha.');
    }

    if (function_exists('audit_log')) {
        audit_log(
            $adminReset ? 'password_reset_by_admin' : 'password_changed',
            'app_user',
            $userId,
            ['must_change_password' => $mustChangePassword]
        );
    }

    return $version;
}

function access_has_valid_activation(PDO $pdo, string $userId): bool
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT 1
        FROM account_activation_tokens
        WHERE user_id = :user_id
          AND used_at IS NULL
          AND expires_at > NOW()
        LIMIT 1
    SQL);
    $stmt->execute(['user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function access_issue_activation_token(
    PDO $pdo,
    string $userId,
    int $validDays = 7,
    string $requestedFrom = 'automatic'
): array {
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $validDays = max(1, min(30, $validDays));

    $pdo->prepare(<<<'SQL'
        UPDATE account_activation_tokens
        SET used_at = NOW()
        WHERE user_id = :user_id
          AND used_at IS NULL
    SQL)->execute(['user_id' => $userId]);

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO account_activation_tokens(
            user_id, token_hash, expires_at, delivery_channel, requested_from
        ) VALUES(
            :user_id, :token_hash,
            NOW() + (:valid_days || ' days')::interval,
            'whatsapp', :requested_from
        )
        RETURNING id, expires_at
    SQL);
    $stmt->execute([
        'user_id' => $userId,
        'token_hash' => $hash,
        'valid_days' => $validDays,
        'requested_from' => $requestedFrom,
    ]);
    $row = $stmt->fetch() ?: [];

    return [
        'token_id' => $row['id'] ?? null,
        'token' => $plain,
        'activation_url' => access_base_url() . '/activate-account.php?token=' . rawurlencode($plain),
        'expires_at' => $row['expires_at'] ?? null,
    ];
}

function access_find_student_user(PDO $pdo, string $studentId, string $phone): ?array
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT *
        FROM app_users
        WHERE student_id = :student_id
          AND role = 'student'
        ORDER BY created_at ASC
        LIMIT 1
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    if ($phone === '') {
        return null;
    }

    $stmt = $pdo->prepare(<<<'SQL'
        SELECT *
        FROM app_users
        WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
          AND role = 'student'
        ORDER BY created_at ASC
        LIMIT 1
    SQL);
    $stmt->execute(['phone' => $phone]);
    return $stmt->fetch() ?: null;
}

function ensure_student_portal_access(
    PDO $pdo,
    string $studentId,
    string $name,
    string $phone,
    ?string $email = null,
    bool $issueIfMissing = true,
    bool $forceNewToken = false,
    string $requestedFrom = 'automatic'
): array {
    $phone = access_normalize_phone($phone);
    $name = trim($name) !== '' ? trim($name) : 'Aluno';
    $email = $email !== null && trim($email) !== '' ? trim($email) : null;
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $user = access_find_student_user($pdo, $studentId, $phone);
        $created = false;

        if ($user && !empty($user['student_id']) && (string)$user['student_id'] !== $studentId) {
            throw new RuntimeException('O telefone já está vinculado a outro aluno.');
        }

        if ($user) {
            $pdo->prepare(<<<'SQL'
                UPDATE app_users
                SET
                    student_id = COALESCE(student_id, :student_id),
                    name = CASE WHEN name = 'Aluno' THEN :name ELSE name END,
                    email = COALESCE(email, :email),
                    phone = COALESCE(phone, :phone),
                    updated_at = NOW()
                WHERE id = :id
            SQL)->execute([
                'student_id' => $studentId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'id' => $user['id'],
            ]);
        } else {
            $username = access_unique_username($pdo, $name, $phone);
            $randomPassword = bin2hex(random_bytes(32));

            $stmt = $pdo->prepare(<<<'SQL'
                INSERT INTO app_users(
                    student_id, name, email, phone, username, password_hash,
                    role, status, must_change_password, access_origin
                ) VALUES(
                    :student_id, :name, :email, :phone, :username, :password_hash,
                    'student', 'pending_activation', TRUE, 'whatsapp_auto'
                )
                RETURNING *
            SQL);
            $stmt->execute([
                'student_id' => $studentId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'username' => $username,
                'password_hash' => password_hash($randomPassword, PASSWORD_DEFAULT),
            ]);
            $user = $stmt->fetch();
            $created = true;
        }

        if (!$user) {
            $user = access_find_student_user($pdo, $studentId, $phone);
        }

        if (!$user) {
            throw new RuntimeException('Não foi possível criar o acesso do aluno.');
        }

        // Atualiza a instância local para refletir possíveis alterações feitas acima.
        $user = access_find_student_user($pdo, $studentId, $phone) ?: $user;

        $activation = null;
        $needsActivation = (string)$user['status'] !== 'active';
        $hasValidActivation = $needsActivation
            ? access_has_valid_activation($pdo, (string)$user['id'])
            : false;

        if ($needsActivation && ($forceNewToken || ($issueIfMissing && !$hasValidActivation))) {
            $activation = access_issue_activation_token(
                $pdo,
                (string)$user['id'],
                7,
                $requestedFrom
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        $loginUrl = access_base_url() . '/login.php';
        $activationUrl = $activation['activation_url'] ?? null;
        $username = (string)($user['username'] ?? '');
        $message = null;

        if ($activationUrl) {
            $message = "Seu acesso ao portal RS English está pronto.\n\nEscolha seu usuário e crie sua senha neste link:\n{$activationUrl}\n\nUsuário sugerido: {$username}";
        } elseif (!$needsActivation) {
            $message = "Seu acesso ao portal RS English já está ativo.\n\nEntre por:\n{$loginUrl}\n\nUsuário: {$username}\nVocê também pode entrar com seu telefone ou e-mail.";
        }

        return [
            'ok' => true,
            'created' => $created,
            'user_id' => (string)$user['id'],
            'student_id' => $studentId,
            'username' => $username,
            'phone' => $phone,
            'status' => $user['status'] ?? 'pending_activation',
            'needs_activation' => $needsActivation,
            'activation_url' => $activationUrl,
            'activation_expires_at' => $activation['expires_at'] ?? null,
            'login_url' => $loginUrl,
            'whatsapp_message' => $message,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function access_validate_activation_token(string $plainToken): ?array
{
    if ($plainToken === '') {
        return null;
    }

    $stmt = db()->prepare(<<<'SQL'
        SELECT
            t.id AS token_id,
            t.user_id,
            t.expires_at,
            u.student_id,
            u.name,
            u.email,
            u.phone,
            u.username,
            u.status
        FROM account_activation_tokens t
        INNER JOIN app_users u ON u.id = t.user_id
        WHERE t.token_hash = :token_hash
          AND t.used_at IS NULL
          AND t.expires_at > NOW()
          AND u.role = 'student'
          AND u.status IN ('pending_activation', 'active')
        LIMIT 1
    SQL);
    $stmt->execute(['token_hash' => hash('sha256', $plainToken)]);
    return $stmt->fetch() ?: null;
}

function access_activate_account(
    array $record,
    string $password,
    ?string $email = null,
    ?string $username = null
): int {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $email = $email !== null && trim($email) !== '' ? trim($email) : null;
        $selectedUsername = $username !== null && trim($username) !== ''
            ? access_validate_username($pdo, $username, (string)$record['user_id'])
            : (string)$record['username'];

        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE app_users
            SET
                username = :username_value,
                username_changed_at = CASE WHEN username IS DISTINCT FROM :username_compare THEN NOW() ELSE username_changed_at END,
                password_hash = :password_hash,
                email = COALESCE(:email, email),
                status = 'active',
                must_change_password = FALSE,
                password_changed_at = NOW(),
                activated_at = COALESCE(activated_at, NOW()),
                failed_login_count = 0,
                locked_until = NULL,
                auth_version = COALESCE(auth_version, 1) + 1,
                updated_at = NOW()
            WHERE id = :user_id
            RETURNING auth_version
        SQL);
        $stmt->execute([
            'username_value' => $selectedUsername,
            'username_compare' => $selectedUsername,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'user_id' => $record['user_id'],
        ]);
        $authVersion = (int)$stmt->fetchColumn();

        if (!empty($record['student_id']) && $email !== null) {
            $pdo->prepare(<<<'SQL'
                UPDATE students
                SET email = COALESCE(:email, email), updated_at = NOW()
                WHERE id = :student_id
            SQL)->execute([
                'email' => $email,
                'student_id' => $record['student_id'],
            ]);
        }

        $pdo->prepare(<<<'SQL'
            UPDATE account_activation_tokens
            SET used_at = NOW()
            WHERE id = :token_id
        SQL)->execute(['token_id' => $record['token_id']]);

        $pdo->prepare(<<<'SQL'
            UPDATE account_activation_tokens
            SET used_at = NOW()
            WHERE user_id = :user_id
              AND used_at IS NULL
        SQL)->execute(['user_id' => $record['user_id']]);

        $pdo->commit();

        if (function_exists('audit_log')) {
            audit_log('account_activated', 'app_user', (string)$record['user_id'], [
                'username' => $selectedUsername,
            ]);
        }

        return $authVersion;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
