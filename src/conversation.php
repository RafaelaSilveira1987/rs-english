<?php

declare(strict_types=1);

function conversation_topic(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return 'daily_life';
    }

    $value = mb_substr($value, 0, 120);

    return preg_replace('/[^\pL\pN _-]+/u', '', $value) ?: 'daily_life';
}

function conversation_style(string $value): string
{
    return in_array($value, ['guided', 'free', 'roleplay'], true)
        ? $value
        : 'guided';
}

function conversation_max_turns(mixed $value): int
{
    return max(4, min(30, (int)$value ?: 10));
}

function conversation_mode(string $value): string
{
    $allowed = [
        'conversation',
        'lesson',
        'review',
        'pronunciation',
        'roleplay',
    ];

    return in_array($value, $allowed, true)
        ? $value
        : 'conversation';
}
