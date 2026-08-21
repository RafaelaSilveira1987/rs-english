# RS English v17 — Diagnóstico adaptativo e tradução sob demanda

## Objetivo

Melhorar a avaliação inicial da Emma e impedir a tradução automática de todas as frases.

## Alterações

- diagnóstico ampliado para 8 etapas;
- autoavaliação usada somente para escolher a primeira dificuldade;
- coleta de evidências em compreensão, estrutura, leitura, produção, interação, coerência e áudio opcional;
- nenhuma pergunta traz a resposta pronta ou um modelo que resolva a própria tarefa;
- tradução somente quando o aluno pedir explicitamente ou demonstrar bloqueio;
- respostas corretas avançam para produção mais livre, evitando repetição de frases do tipo “I like...”;
- pontuações acumulam as evidências do histórico;
- pronúncia permanece não avaliada quando não houver áudio;
- portal do aluno atualizado para exibir 8 etapas.

## Instalação

1. Faça backup do projeto e do PostgreSQL.
2. Atualize os arquivos PHP/CSS desta versão e faça o redeploy do serviço web.
3. No n8n, desative os workflows antigos que usam os mesmos webhooks.
4. Importe:

```text
docs/fluxos/rs-english-n8n-v17-diagnostico-adaptativo.json
docs/fluxos/rs-english-n8n-v17-web-portal-diagnostico.json
```

5. Selecione novamente a credencial OpenAI nos nós de IA, caso o n8n solicite.
6. Ative os dois workflows.

## Migration

Não há migration nova nesta versão.

## Teste recomendado

Use um aluno de teste com diagnóstico pendente. Caso o aluno já tenha diagnóstico concluído, solicite “refazer diagnóstico”.

Confirme:

- primeira mensagem em português;
- escolha inicial não define o nível final;
- nenhuma pergunta em inglês aparece acompanhada da tradução;
- pedido “o que significa?” mantém a mesma etapa;
- o diagnóstico só termina na etapa 8;
- o resultado final registra pontuações e evidências coerentes.
