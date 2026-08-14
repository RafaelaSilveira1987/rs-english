<?php

declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    return $value;
}
