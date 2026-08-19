# Integração n8n do portal v11

O portal possui dois pontos de integração. Os workflows importáveis estão em `docs/fluxos/rs-english-n8n-v11-web-portal.json` e `docs/fluxos/rs-english-n8n-v11-activity-evaluator.json`.

## Conversa e diagnóstico

Variável:

```env
N8N_WEB_TEACHER_URL=https://n8n.rsautomacaodigital.cloud/webhook/rs-english-web
```

O endpoint PHP é:

```text
public/api/web/teacher.php
```

Ele envia `mode=conversation` ou `mode=diagnostic`. O fluxo deve consultar o contexto do aluno, gerar a resposta, salvar a interação ou o diagnóstico e responder ao webhook.

## Atividades

Variável opcional:

```env
N8N_WEB_ACTIVITY_URL=https://n8n.rsautomacaodigital.cloud/webhook/rs-english-activity
```

O fluxo recebe a atividade e a resposta do aluno. Deve retornar JSON com `score` entre 0 e 100 e `feedback` curto. O portal tem fallback local caso esse webhook não esteja configurado ou não responda.

## Credenciais

Use credenciais do n8n para OpenAI. Não use chave manual em cabeçalho de nós exportados. Para chamadas ao PHP, use `X-API-Key` com a variável de ambiente ou uma credencial Header Auth.
