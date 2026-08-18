# Instalação — RS English v10.5

## 1. Substituir os arquivos

Envie o conteúdo completo do ZIP para o serviço web do RS English e faça rebuild/redeploy.

Os nós do n8n não foram alterados no pacote.

## 2. Executar a migration

No Adminer, execute:

```sql
-- arquivo:
database/028_diagnostic_save_hardening.sql
```

Ela:

- amplia os campos de nível para `VARCHAR(10)`;
- permite `PRE-A1`;
- adiciona colunas do diagnóstico adaptativo;
- garante `messages.transcription`;
- cria as tabelas auxiliares do diagnóstico quando necessário;
- corrige permissões quando a role `rsenglish_app` existe.

## 3. Variável de depuração

Em produção, mantenha:

```env
APP_DEBUG=false
```

Durante um teste controlado, pode usar temporariamente:

```env
APP_DEBUG=true
```

Com `APP_DEBUG=true`, o endpoint informa o detalhe técnico do erro. Depois do teste, volte para `false` e faça redeploy.

Mesmo com depuração desativada, o retorno apresenta:

```json
{
  "success": false,
  "error": "Não foi possível salvar o diagnóstico.",
  "stage": "nome_da_etapa",
  "error_reference": "diag-..."
}
```

A referência também aparece no log do container.

## 4. Body do n8n

O nó `Salvar Diagnóstico PHP1` continua sendo configurado manualmente. O endpoint espera:

```json
{
  "phone": "...",
  "student_name": "...",
  "student_message": "...",
  "teacher_message": "...",
  "message_type": "text ou audio",
  "diagnostic": {}
}
```

## 5. Retorno esperado

Durante o diagnóstico:

```json
{
  "success": true,
  "complete": false,
  "student_id": "UUID",
  "session_id": "UUID",
  "next_step": 2,
  "estimated_level": "A1",
  "warnings": []
}
```

Na conclusão:

```json
{
  "success": true,
  "complete": true,
  "student_id": "UUID",
  "session_id": "UUID",
  "official_level": "A1",
  "target_level": "A2",
  "warnings": []
}
```

Uma lista em `warnings` indica que o diagnóstico principal foi salvo, mas algum registro auxiliar precisa ser revisado.
