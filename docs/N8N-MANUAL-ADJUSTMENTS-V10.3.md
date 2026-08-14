# Ajustes manuais no n8n — RS English v10.3

O ZIP já contém as correções PHP, banco, cadastro automático, PRE-A1, classificação, registro de áudio e correções. Os itens abaixo permanecem manuais no workflow n8n.

## 1. Buscar Contexto PHP1

Query Parameters:

```text
phone = {{ $('Normalizar Entrada1').item.json.phone }}
name  = {{ $('Normalizar Entrada1').item.json.name }}
```

O endpoint agora cria o aluno e o perfil automaticamente e retorna:

```json
{
  "found": true,
  "student_id": "UUID",
  "student": {
    "id": "UUID",
    "overall_level": "PRE-A1"
  }
}
```

## 2. Precisa Diagnóstico?1

Use condição booleana, não string.

Left value:

```javascript
={{
  $json.found === false ||
  (($json.student?.diagnostic_status ?? $json.diagnostic_status ?? 'pending') !== 'completed')
}}
```

Operator:

```text
Boolean → Equals → true
```

## 3. Áudio recebido

Troque o nó `Translate a recording` por:

```text
Resource: Audio
Operation: Transcribe a Recording
Binary field: data
```

Não use `Translate`, pois ele converte o conteúdo para inglês.

No nó `Buscar Áudio Base64`, use a chave atual da Evolution em variável/credencial:

```javascript
={{ $env.EVOLUTION_API_KEY }}
```

## 4. Prompt do Teacher

Copie o conteúdo de:

```text
docs/TEACHER-CORRECTIONS-PROMPT-V2.txt
```

Regra central:

- correção ou confirmação em português;
- continuação somente em inglês;
- tradução da pergunta somente quando o aluno não entender.

## 5. Salvar Diagnóstico PHP1

Body:

```javascript
={{ {
  phone: $('Normalizar Entrada1').item.json.phone,
  student_name: $('Normalizar Entrada1').item.json.name,
  student_message: $json.message,
  teacher_message: $json.teacher_message,
  message_type: $('Normalizar Entrada1').item.json.is_audio ? 'audio' : 'text',
  diagnostic: $json.diagnostic
} }}
```

O PHP agora:

- permite PRE-A1;
- grava o tipo de mensagem;
- atualiza a classificação final;
- cria o plano de 28 dias;
- grava relatório e correções do diagnóstico.

## 6. Preparar Payload Correções

Conexão:

```text
Juntar Teacher + Avaliação1
→ Preparar Payload Correções
→ Salvar Correções PHP
```

Código:

```javascript
const context = $('Buscar Contexto PHP1').item.json;
const original = $('Normalizar Entrada1').item.json;

const studentId =
  context.student_id ??
  context.student?.id ??
  '';

const corrections = Array.isArray($json.evaluation?.corrections)
  ? $json.evaluation.corrections
  : [];

if (!studentId) {
  throw new Error('O context.php não retornou student_id.');
}

return [{
  json: {
    student_id: studentId,
    phone: original.phone,
    session_id: $json.session_id ?? null,
    channel: original.is_audio ? 'whatsapp_voice' : 'whatsapp',
    corrections
  }
}];
```

No `Salvar Correções PHP`, Body JSON:

```javascript
={{ $json }}
```

O endpoint também aceita `phone` como fallback.

## 7. Resposta por áudio no WhatsApp

Depois de `Juntar Teacher + Avaliação1`, crie um IF:

```javascript
={{ $('Normalizar Entrada1').item.json.is_audio }}
```

### Ramo false

```text
Responder WhatsApp1
```

### Ramo true

Crie `Gerar Voz Emma`:

```text
POST https://rsenglish.rsautomacaodigital.cloud/api/voice/synthesize.php
```

Headers:

```text
X-API-Key: SUA_N8N_API_KEY
Content-Type: application/json
```

Body:

```javascript
={{ {
  text: $('Juntar Teacher + Avaliação1').item.json.teacher_message,
  voice: 'coral',
  speed: 1,
  format: 'mp3',
  return_base64: true
} }}
```

Resposta:

```json
{
  "ok": true,
  "mime": "audio/mpeg",
  "format": "mp3",
  "base64": "..."
}
```

Use o `base64` no nó de envio de áudio/PTT da sua versão da Evolution API. A rota pode variar por versão, normalmente `sendWhatsAppAudio` ou envio de mídia com `mediatype=audio`.

Não conecte o ramo de áudio também ao `sendText`, para evitar resposta duplicada.

## 8. Credenciais

Não deixe chaves diretamente nos nodes JSON.

Use:

- credencial OpenAI nativa do n8n;
- `EVOLUTION_API_KEY` no ambiente do n8n;
- `N8N_API_KEY` no PHP e no header dos endpoints internos.

Remova headers manuais `Authorization: Bearer sk-...` dos nós que já usam credencial OpenAI.
