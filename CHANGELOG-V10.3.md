# RS English v10.3 — correções consolidadas

## PHP

- `context.php` cria aluno/perfil no primeiro contato e sempre retorna `student_id`.
- `save-diagnostic.php` aceita `PRE-A1`, registra texto/áudio, finaliza classificação, gera plano e relatório.
- `save-corrections.php` aceita UUID ou telefone e normaliza o payload.
- `save-diagnostic-feedback.php` aceita `PRE-A1` e atualiza o perfil.
- erros internos ficam no log; detalhes só aparecem fora de produção.

## Banco

- migrations antigas atualizadas para níveis com até 10 caracteres.
- nova migration `026_pre_a1_context_voice_fixes.sql` para bancos já existentes.
- índice de telefone normalizado para busca do WhatsApp.

## Áudio

- Dockerfile prepara `storage/voice` com permissões.
- `.env.example` inclui OpenAI, modelos e webhook web do n8n.
- guia de resposta de áudio no WhatsApp atualizado para base64.

## n8n

Os nodes não foram alterados automaticamente. Use:

```text
docs/N8N-MANUAL-ADJUSTMENTS-V10.3.md
```
