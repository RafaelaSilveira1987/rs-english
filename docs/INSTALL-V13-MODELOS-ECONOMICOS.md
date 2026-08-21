# RS English v13 — modelos econômicos

## Modelos definidos

```env
OPENAI_TEXT_MODEL=gpt-5.6-luna
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TTS_MODEL=gpt-4o-mini-tts
```

- `gpt-5.6-luna`: conversas da Emma, diagnóstico adaptativo, correções, avaliação de atividades e relatórios.
- `gpt-4o-mini-transcribe`: transcrição de áudio do WhatsApp e do portal.
- `gpt-4o-mini-tts`: voz da Emma no portal e no WhatsApp.

## EasyPanel — serviço n8n

Adicione ou confirme:

```env
OPENAI_TEXT_MODEL=gpt-5.6-luna
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
N8N_BLOCK_ENV_ACCESS_IN_NODE=false
```

Mantenha a credencial OpenAI cadastrada no n8n. Não grave a chave diretamente nos workflows.

## EasyPanel — serviço web/PHP

Adicione ou confirme:

```env
OPENAI_API_KEY= SUA_CHAVE_SEM_ESPACO_APOS_IGUAL
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TTS_MODEL=gpt-4o-mini-tts
```

Corrija a linha de `OPENAI_API_KEY` removendo o espaço usado apenas como marcador no exemplo acima.

## Workflows a importar

Desative as versões anteriores que usam os mesmos webhooks e importe:

```text
docs/fluxos/rs-english-n8n-v13-adaptive-economico.json
docs/fluxos/rs-english-n8n-v13-web-portal-economico.json
docs/fluxos/rs-english-n8n-v13-activity-evaluator-economico.json
```

Depois selecione a credencial OpenAI nos nós HTTP da OpenAI, salve e ative os fluxos.

## Alteração técnica aplicada

- todos os requests de texto usam `$env.OPENAI_TEXT_MODEL`, com fallback para `gpt-5.6-luna`;
- todos os requests de transcrição usam `$env.OPENAI_TRANSCRIPTION_MODEL`, com fallback para `gpt-4o-mini-transcribe`;
- os endpoints PHP usam `OPENAI_TTS_MODEL`, com fallback para `gpt-4o-mini-tts`;
- nós antigos de transcrição sem modelo explícito foram substituídos por HTTP Request com multipart/form-data;
- a língua da transcrição ficou em detecção automática, porque o acolhimento inicial pode misturar português e inglês.

## Observação

A troca reduz custo, mas o comportamento pedagógico ainda depende do tamanho dos prompts e do histórico enviado. Para controlar o consumo, mantenha apenas as mensagens recentes e o resumo do perfil do aluno em cada chamada.
