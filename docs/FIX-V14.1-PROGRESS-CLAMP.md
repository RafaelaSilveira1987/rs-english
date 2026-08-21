# RS English v14.1 — Correção progress_clamp

## Problema
Com `declare(strict_types=1)`, o PHP rejeitava valores `NUMERIC/DECIMAL` retornados pelo PostgreSQL/PDO como strings, por exemplo `"1.00"`, na função `progress_clamp(float|int|null $value)`.

## Correção
`progress_clamp()` agora recebe `mixed`, valida com `is_numeric()` e converte explicitamente para `float`. Valores nulos, vazios ou não numéricos viram `0.0`.

Não há migration de banco nesta correção. Basta substituir `src/progress.php` ou usar o pacote v14.1 completo.
