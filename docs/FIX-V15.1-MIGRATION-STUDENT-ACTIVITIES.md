# RS English v15.1 — Correção da migration 033

A tabela `student_activities` usa `assigned_at` como data de criação/atribuição e não possui a coluna `created_at`.

Foram corrigidas as duas ocorrências na migration `database/033_learning_telemetry.sql`:

```sql
COALESCE(sa.completed_at, sa.assigned_at, a.created_at, NOW())
```

Como a migration é idempotente, execute primeiro `ROLLBACK;` caso a sessão ainda esteja em transação abortada e depois execute novamente o arquivo completo `database/033_learning_telemetry.sql`.
