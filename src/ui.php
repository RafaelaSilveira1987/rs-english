<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ui_first_name(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') return 'Aluno';
    return preg_split('/\s+/', $name)[0] ?? $name;
}

function ui_initials(?string $name): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim((string)$name)) ?: []));
    if (!$parts) return 'RS';
    $letters = mb_substr($parts[0], 0, 1);
    if (count($parts) > 1) $letters .= mb_substr($parts[count($parts)-1], 0, 1);
    return mb_strtoupper($letters);
}

function ui_role_label(?string $role): string
{
    return match ($role) {
        'owner' => 'Proprietário',
        'admin' => 'Administrador',
        'teacher' => 'Professor',
        'student' => 'Aluno',
        default => 'Usuário',
    };
}


function ui_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) return $value;
    if ($value === null || $value === '') return $default;
    return in_array(strtolower((string)$value), ['1','true','t','yes','on'], true);
}

function ui_status_label(?string $status): string
{
    return match (strtolower(trim((string)$status))) {
        'active' => 'Ativo',
        'inactive' => 'Inativo',
        'pending' => 'Pendente',
        'in_progress' => 'Em andamento',
        'completed' => 'Concluído',
        'generated' => 'Gerado',
        'indexed' => 'Indexado',
        'learning' => 'Em aprendizado',
        'review' => 'Revisão',
        'mastered' => 'Dominado',
        'archived' => 'Arquivado',
        'light' => 'Leve',
        'balanced' => 'Equilibrada',
        'intensive' => 'Intensiva',
        'guided' => 'Guiada',
        'free' => 'Livre',
        'roleplay' => 'Simulação',
        'text' => 'Texto',
        'audio' => 'Áudio',
        'conversation' => 'Conversação',
        'assessment' => 'Avaliação',
        'error' => 'Erro',
        default => ucfirst(str_replace('_', ' ', (string)$status)),
    };
}

function ui_status_class(?string $status): string
{
    return match (strtolower(trim((string)$status))) {
        'active', 'completed', 'generated', 'indexed', 'mastered' => 'success',
        'pending', 'in_progress', 'learning', 'review' => 'warning',
        'inactive', 'archived' => 'neutral',
        'error', 'failed' => 'danger',
        default => 'neutral',
    };
}

function ui_level_class(?string $level): string
{
    return match (strtoupper(trim((string)$level))) {
        'PRE-A1' => 'level-pre',
        'A1' => 'level-a1',
        'A2' => 'level-a2',
        'B1' => 'level-b1',
        'B2' => 'level-b2',
        'C1' => 'level-c1',
        'C2' => 'level-c2',
        default => 'level-default',
    };
}

function ui_date(?string $value, string $fallback = '—'): string
{
    if (!$value) return $fallback;
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $fallback;
}

function ui_date_only(?string $value, string $fallback = '—'): string
{
    if (!$value) return $fallback;
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : $fallback;
}

function ui_relative_date(?string $value, string $fallback = 'Sem atividade'): string
{
    if (!$value) return $fallback;
    $timestamp = strtotime($value);
    if (!$timestamp) return $fallback;
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Agora';
    if ($diff < 3600) return floor($diff / 60) . ' min atrás';
    if ($diff < 86400) return floor($diff / 3600) . ' h atrás';
    if ($diff < 604800) return floor($diff / 86400) . ' dias atrás';
    return date('d/m/Y', $timestamp);
}

function ui_percent(mixed $value): float
{
    return max(0, min(100, (float)$value));
}

function ui_json_array(mixed $value): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function ui_topic_label(?string $topic): string
{
    return match ((string)$topic) {
        'daily_life' => 'Rotina e dia a dia',
        'work' => 'Trabalho e carreira',
        'technology' => 'Tecnologia',
        'travel' => 'Viagem',
        'food' => 'Comida e restaurante',
        'movies' => 'Filmes e séries',
        'goals' => 'Planos e objetivos',
        'job_interview' => 'Entrevista de emprego',
        'free_conversation' => 'Conversação livre',
        'initial_diagnostic' => 'Diagnóstico inicial',
        default => ucfirst(str_replace('_', ' ', (string)$topic)),
    };
}

function ui_icon(string $name, string $class = 'icon'): string
{
    $paths = [
        'dashboard' => '<path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z"/>',
        'students' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'activities' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'knowledge' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        'curriculum' => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/>',
        'reports' => '<path d="M3 3v18h18"/><path d="m7 16 4-4 3 3 5-7"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-4-4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'bot' => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="8" cy="16" r="1"/><circle cx="16" cy="16" r="1"/><path d="M12 2v4M8 7h8"/>',
        'health' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1.1-1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/><path d="M3.5 12h5l1.5-3 3 6 1.5-3h6"/>',
        'progress' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'practice' => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3M8 22h8"/>',
        'vocabulary' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M8 7h8M8 11h6"/>',
        'plan' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'password' => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'arrow' => '<path d="m9 18 6-6-6-6"/>',
        'sparkles' => '<path d="m12 3-1.5 4.5L6 9l4.5 1.5L12 15l1.5-4.5L18 9l-4.5-1.5L12 3Z"/><path d="m5 15-.75 2.25L2 18l2.25.75L5 21l.75-2.25L8 18l-2.25-.75L5 15ZM19 2l-.75 2.25L16 5l2.25.75L19 8l.75-2.25L22 5l-2.25-.75L19 2Z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'lock' => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'chat' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8Z"/><path d="M8 10h.01M12 10h.01M16 10h.01"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/><path d="m15 9 6-6M17 3h4v4"/>',
    ];
    $path = $paths[$name] ?? $paths['sparkles'];
    return '<svg class="'.e($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
}
