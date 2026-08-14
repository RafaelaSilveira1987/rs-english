# RS English v10 — Conversação por voz

## Resultado

### Web
- gravação pelo microfone;
- pré-escuta;
- transcrição;
- conversa com a Emma;
- avaliação pelo fluxo já existente;
- resposta escrita;
- resposta falada;
- autoplay configurável;
- armazenamento do histórico de voz.

### WhatsApp
A v10 deixa prontos os endpoints reutilizáveis para:
- transcrever o áudio recebido;
- gerar o áudio da Emma;
- devolver base64 para o n8n;
- enviar pela Evolution como áudio/PTT.

A conexão final do PTT será feita quando a Evolution API estiver estável.

## Instalação

### 1. Migration
Execute:

`database/023_voice_conversation.sql`

### 2. Backend
Adicione:

- `src/audio.php`
- `public/api/voice/transcribe.php`
- `public/api/voice/synthesize.php`
- `public/api/web/voice.php`
- `public/voice-media.php`

### 3. Portal
Substitua:

- `public/portal/practice.php`

Adicione:

- `public/assets/js/voice-practice.js`

Anexe o conteúdo de:

- `public/assets/css/v10-append.css`

ao final do seu:

- `public/assets/css/app.css`

### 4. EasyPanel
Adicione:

```env
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TTS_MODEL=gpt-4o-mini-tts
```

Mantenha:

```env
OPENAI_API_KEY=...
N8N_WEB_TEACHER_URL=...
N8N_API_KEY=...
```

### 5. Volume
Crie volume persistente:

`/var/www/html/storage/voice`

### 6. Docker
Confira:

`docs/DOCKER-V10.txt`

### 7. Teste Web
Entre como aluno:

`/portal/practice.php`

Selecione:

`Conversar por áudio`

Grave uma frase em inglês e envie.

A resposta deverá trazer:
- transcrição;
- texto da Emma;
- player com a voz da Emma.

### 8. WhatsApp
Leia:

`docs/WHATSAPP-VOICE-INTEGRATION.md`

## Observação pedagógica
A transcrição permite avaliar conteúdo, gramática, vocabulário, compreensão
e parte da fluência conversacional. Ela não é suficiente para afirmar uma
nota precisa de pronúncia. A análise de pronúncia será uma etapa específica.

---

## Atualização v10.3 — agosto de 2026

Esta versão inclui:

- cadastro automático de aluno pelo `context.php`;
- retorno padronizado de `student_id`;
- suporte completo a `PRE-A1`;
- classificação oficial ao concluir o diagnóstico;
- registro correto de mensagem de texto ou áudio;
- relatório detalhado do diagnóstico;
- persistência das correções durante o diagnóstico;
- endpoint de correções mais tolerante, com fallback por telefone;
- Dockerfile consolidado e diretório de voz preparado;
- prompt revisado: correção em português e continuidade em inglês;
- guia dos ajustes manuais do n8n.

Para banco existente, execute por último:

```text
database/026_pre_a1_context_voice_fixes.sql
```

Os ajustes do workflow permanecem documentados em:

```text
docs/N8N-MANUAL-ADJUSTMENTS-V10.3.md
```
