# RS English v10.5 — Correção do salvamento do diagnóstico

## Corrigido

- Corrige o envio de booleanos ao PostgreSQL em `pre_a1` e `accepted`.
- Evita que `false` seja convertido em string vazia pelo PDO PgSQL.
- Adiciona identificação da etapa exata em que o diagnóstico falhou.
- Adiciona referência de erro para localizar o registro no log do container.
- Mantém o diagnóstico principal salvo mesmo quando tabelas auxiliares de relatório, correção ou plano ainda apresentam incompatibilidade.
- Amplia colunas de nível para aceitar `PRE-A1`.
- Remove CHECKs legados que rejeitam `PRE-A1` e adiciona validações compatíveis.
- Garante a existência das estruturas auxiliares usadas pelo diagnóstico.
- Mantém os nós do n8n fora do ZIP para ajuste manual.

## Arquivos principais

- `public/api/n8n/save-diagnostic.php`
- `database/028_diagnostic_save_hardening.sql`
- `.env.example`
- `docs/INSTALL-V10.5-DIAGNOSTIC-FIX.md`
