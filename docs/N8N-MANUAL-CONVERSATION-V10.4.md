# Ajustes manuais do n8n — conversação v10.4

O ZIP não altera o workflow automaticamente. Aplique os ajustes abaixo no fluxo atual.

## 1. Migration

Execute primeiro:

```text
database/027_conversation_mode.sql
```

## 2. `Buscar Contexto PHP1`

Mantenha:

```text
GET /api/n8n/context.php
```

Query parameters:

```javascript
phone = {{ $('Normalizar Entrada1').item.json.phone }}
name  = {{ $('Normalizar Entrada1').item.json.name }}
```

A resposta agora inclui:

```json
{
  "conversation": {
    "session_id": "...",
    "topic": "technology",
    "style": "guided",
    "turn_count": 4,
    "max_turns": 10,
    "remaining_turns": 6,
    "should_wrap_up": false,
    "should_finish": false
  }
}
```

Quando ainda não existe sessão, `conversation` será `null`.

## 3. `Montar Contexto Professor`

Acrescente ao prompt:

```javascript
const conversation = j.conversation || {
  topic: j.student_preferences?.conversation_topic || 'daily_life',
  style: j.student_preferences?.conversation_style || 'guided',
  turn_count: 0,
  max_turns: j.student_preferences?.conversation_max_turns || 10,
  should_wrap_up: false,
  should_finish: false
};

const correctionMode =
  j.correction_mode ||
  j.student_preferences?.correction_mode ||
  s.correction_mode ||
  'balanced';
```

Inclua no texto do prompt:

```text
Tema: ${conversation.topic}
Formato: ${conversation.style}
Interação: ${conversation.turn_count} de ${conversation.max_turns}
Preparar encerramento: ${conversation.should_wrap_up}
Encerrar agora: ${conversation.should_finish}
Modo de correção: ${correctionMode}
```

Use integralmente as regras do arquivo:

```text
docs/TEACHER-CONVERSATION-PROMPT-V10.4.txt
```

Regra principal:

```text
Correção ou confirmação em português.
Continuação da conversa em inglês.
Tradução apenas quando o aluno disser que não entendeu.
```

## 4. Não repetir perguntas

No prompt, inclua:

```text
Nunca repita a última pergunta.
Considere a mensagem atual uma resposta à última pergunta da Emma.
Não repita um exercício que o aluno já respondeu corretamente.
Use recent_messages para continuar do ponto atual.
```

## 5. Evaluator

Acrescente ao JSON retornado:

```json
{
  "session_complete": false,
  "session_summary": "",
  "summary_data": {
    "strengths": [],
    "important_corrections": [],
    "next_activity": ""
  }
}
```

Quando `conversation.should_finish=true`, o Evaluator deve retornar:

```json
{
  "session_complete": true,
  "session_summary": "Resumo curto da conversa.",
  "summary_data": {
    "strengths": ["...", "..."],
    "important_corrections": ["..."],
    "next_activity": "..."
  }
}
```

## 6. `Salvar Interação PHP1`

Use este body:

```javascript
={{ {
  phone: $('Normalizar Entrada1').item.json.phone,
  student_name: $('Normalizar Entrada1').item.json.name,
  student_message: $('Juntar Teacher + Avaliação1').item.json.message,
  teacher_message: $('Juntar Teacher + Avaliação1').item.json.teacher_message,
  message_type: $('Normalizar Entrada1').item.json.is_audio ? 'audio' : 'text',
  channel: 'whatsapp',
  mode: 'conversation',
  topic:
    $('Juntar Teacher + Avaliação1').item.json.conversation?.topic
    || $('Juntar Teacher + Avaliação1').item.json.student_preferences?.conversation_topic
    || 'daily_life',
  conversation: {
    style:
      $('Juntar Teacher + Avaliação1').item.json.conversation?.style
      || 'guided',
    max_turns:
      $('Juntar Teacher + Avaliação1').item.json.conversation?.max_turns
      || 10
  },
  session_end:
    $('Juntar Teacher + Avaliação1').item.json.evaluation?.session_complete
    || false,
  session_summary:
    $('Juntar Teacher + Avaliação1').item.json.evaluation?.session_summary
    || '',
  summary_data:
    $('Juntar Teacher + Avaliação1').item.json.evaluation?.summary_data
    || {},
  evaluation: $('Juntar Teacher + Avaliação1').item.json.evaluation
} }}
```

O retorno passa a incluir:

```json
{
  "conversation": {
    "turn_count": 5,
    "max_turns": 10,
    "remaining_turns": 5,
    "should_wrap_up": false,
    "completed": false
  }
}
```

## 7. Iniciar uma nova sessão explicitamente

Endpoint disponível:

```text
POST /api/n8n/start-conversation.php
```

Body:

```javascript
={{ {
  phone: $('Normalizar Entrada1').item.json.phone,
  student_name: $('Normalizar Entrada1').item.json.name,
  channel: 'whatsapp',
  topic: 'technology',
  style: 'guided',
  max_turns: 10
} }}
```

Use esse endpoint quando o aluno escrever, por exemplo:

```text
quero praticar conversação
vamos conversar sobre tecnologia
iniciar nova conversa
```

## 8. Encerrar manualmente

Endpoint:

```text
POST /api/n8n/end-conversation.php
```

Body:

```javascript
={{ {
  session_id: $json.conversation.session_id,
  reason: 'student_requested',
  summary: $json.evaluation.session_summary,
  summary_data: $json.evaluation.summary_data
} }}
```

## 9. Áudio no WhatsApp

Mantenha:

```text
texto recebido → resposta em texto
áudio recebido → resposta em áudio
```

O ramo de áudio continua usando:

```text
Gerar Voz Emma
→ Enviar Áudio WhatsApp
```

Não conecte o mesmo item ao `sendText`, para evitar resposta duplicada.

## 10. Ordem recomendada

```text
Buscar contexto
→ verificar diagnóstico
→ montar contexto de conversação
├── Teacher IA
└── Evaluator IA
→ juntar respostas
├── salvar interação
├── salvar correções
└── escolher resposta texto/áudio
```

## 11. Testes

### Teste 1 — frase correta

Aluno:

```text
I study physics every day.
```

Esperado:

```text
Ótimo! Sua frase está correta. 👏

What do you enjoy most about physics?
```

Não deve aparecer tradução automática.

### Teste 2 — erro

Aluno:

```text
I work with developer.
```

Esperado:

```text
Entendi!

Uma forma melhor seria:
"I work as a developer."

Usamos "work as" para falar da profissão.

What kind of software do you develop?
```

### Teste 3 — não entendi

Aluno:

```text
Não entendi.
```

Esperado:

- tradução da última pergunta;
- exemplo curto;
- repetição da pergunta em inglês;
- sem encerrar a sessão.

### Teste 4 — repetição

A Emma não pode repetir uma pergunta já respondida corretamente.

### Teste 5 — encerramento

Ao atingir `max_turns`, deve retornar resumo e não fazer uma nova pergunta.
