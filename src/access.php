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

function access_unique_username(PDO $pdo, string $phone): string
{
    $base = $phone !== '' ? $phone : 'aluno_' . bin2hex(random_bytes(4));
    $candidate = $base;
    $suffix = 0;

    while (true) {
        $stmt = $pdo->prepare('SELECT 1 FROM app_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $suffix++;
        $candidate = 'aluno_' . substr($base, -10) . '_' . $suffix;
    }
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
            $username = access_unique_username($pdo, $phone);
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
        $message = null;

        if ($activationUrl) {
            $message = "Seu acesso ao portal RS English está pronto.\n\nCrie sua senha neste link:\n{$activationUrl}\n\nDepois, entre usando seu número do WhatsApp: {$phone}";
        } elseif (!$needsActivation) {
            $message = "Seu acesso ao portal RS English já está ativo.\n\nEntre por:\n{$loginUrl}\n\nUse seu número do WhatsApp ou seu usuário cadastrado.";
        }

        return [
            'ok' => true,
            'created' => $created,
            'user_id' => (string)$user['id'],
            'student_id' => $studentId,
            'username' => $user['username'] ?? $phone,
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

function access_activate_account(array $record, string $password, ?string $email = null): void
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $email = $email !== null && trim($email) !== '' ? trim($email) : null;

        $pdo->prepare(<<<'SQL'
            UPDATE app_users
            SET
                password_hash = :password_hash,
                email = COALESCE(:email, email),
                status = 'active',
                must_change_password = FALSE,
                password_changed_at = NOW(),
                activated_at = COALESCE(activated_at, NOW()),
                failed_login_count = 0,
                locked_until = NULL,
                updated_at = NOW()
            WHERE id = :user_id
        SQL)->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'user_id' => $record['user_id'],
        ]);

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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
