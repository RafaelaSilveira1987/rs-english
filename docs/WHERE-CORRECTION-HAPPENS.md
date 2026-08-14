# Onde a correção acontece

## 1. Teacher IA
Mostra a correção ao aluno.

Use:
`TEACHER-CORRECTIONS-PROMPT-V2.txt`

## 2. Evaluator IA
Identifica e estrutura o erro em JSON.

Use:
`EVALUATOR-CORRECTIONS-PROMPT-V2.txt`

## 3. HTTP Request após Evaluator

POST:
`https://SEU_DOMINIO/api/n8n/save-corrections.php`

Headers:
- X-API-Key
- Content-Type: application/json

Body:

```json
{
  "student_id": "={{ $json.student_id }}",
  "session_id": "={{ $json.session_id || null }}",
  "channel": "={{ $json.channel || 'whatsapp' }}",
  "corrections": "={{ $json.evaluation.corrections || [] }}"
}
```

## 4. Banco

Correções:
`correction_events`

Erros recorrentes:
`student_errors`

Resumo:
Teacher corrige para o aluno.
Evaluator estrutura.
save-corrections.php salva.
