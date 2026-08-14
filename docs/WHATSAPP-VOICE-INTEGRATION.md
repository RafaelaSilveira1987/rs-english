# Integração WhatsApp — áudio da Emma

A infraestrutura PHP expõe endpoints protegidos por `X-API-Key`.

## Transcrição

```text
POST /api/voice/transcribe.php
```

Multipart:

```text
audio = arquivo binário
```

Header:

```text
X-API-Key: SUA_N8N_API_KEY
```

## Síntese

```text
POST /api/voice/synthesize.php
```

Body:

```json
{
  "text": "Teacher response",
  "voice": "coral",
  "speed": 1,
  "format": "mp3",
  "return_base64": true
}
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

## Fluxo n8n

1. Receber `messages.upsert`.
2. Detectar áudio.
3. Buscar o base64 na Evolution.
4. Converter para binário `data`.
5. Transcrever, sem traduzir.
6. Enviar a transcrição ao Teacher e Evaluator.
7. Gerar a resposta escrita.
8. Chamar `/api/voice/synthesize.php` com `return_base64=true`.
9. Enviar o base64 pela rota de áudio/PTT suportada pela Evolution.
10. Salvar interação com `message_type=audio` e `channel=whatsapp_voice`.

A rota final de envio depende da versão da Evolution API. Consulte o gerenciador/Swagger da instalação para confirmar se a rota disponível é `sendWhatsAppAudio` ou envio genérico de mídia.
