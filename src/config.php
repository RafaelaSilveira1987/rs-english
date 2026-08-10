<?php

declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}
