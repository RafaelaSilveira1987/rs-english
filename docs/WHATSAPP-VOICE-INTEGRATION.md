# Integração WhatsApp — áudio da Emma

A infraestrutura v10 já expõe dois endpoints protegidos por X-API-Key:

## Transcrição

POST
`https://SEU_DOMINIO/api/voice/transcribe.php`

Multipart:
- `audio` = arquivo binário

Header:
- `X-API-Key: SUA_N8N_API_KEY`

Resposta:
```json
{
  "ok": true,
  "text": "student transcription"
}
```

## Síntese

POST
`https://SEU_DOMINIO/api/voice/synthesize.php`

JSON:
```json
{
  "text": "Teacher response",
  "voice": "coral",
  "speed": 1,
  "format": "mp3",
  "return_base64": true
}
```

Header:
- `X-API-Key: SUA_N8N_API_KEY`

Resposta:
```json
{
  "ok": true,
  "mime": "audio/mpeg",
  "format": "mp3",
  "base64": "..."
}
```

## Fluxo no n8n

1. Webhook Evolution `messages.upsert`.
2. Detectar mensagem de áudio.
3. Evolution `getBase64FromMediaMessage`.
4. Converter base64 para arquivo binário `data`.
5. HTTP Request multipart para `/api/voice/transcribe.php`.
6. Usar `text` como `message` no Teacher/Evaluator.
7. Gerar resposta escrita.
8. HTTP Request JSON para `/api/voice/synthesize.php`.
9. Converter `base64` retornado para binário.
10. Enviar pela Evolution API como áudio/PTT.
11. Salvar interação com:
   - `message_type=audio`
   - `channel=whatsapp_voice`

A URL e o payload de envio PTT dependem da versão e da rota disponível
na sua Evolution API. Quando a instância estiver estável, conecte o último nó
ao endpoint de envio de áudio suportado por ela.
