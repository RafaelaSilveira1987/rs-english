# RS English v18 — Ciclo real do portal do aluno

## Objetivo

Esta versão corrige primeiro a experiência do aluno. O painel passa a explicar e registrar de forma consistente diagnóstico, vocabulário, plano semanal, atividades, áudio, metas, tempo de estudo e conquistas.

## O que foi alterado

### 1. Feedback do diagnóstico

- Converte sequências literais `\\n`, `\\r` e `\\t` antes de exibir.
- Remove caracteres invisíveis comuns em respostas de IA.
- Corrige também registros antigos pela migration 035.

### 2. Vocabulário

O vocabulário é preenchido a partir da avaliação estruturada de:

- conversas com a Emma;
- etapas do diagnóstico;
- respostas de atividades.

Cada avaliação pode registrar até 3 itens relevantes. O backend aceita até 8 por chamada como proteção, exclui palavras funcionais muito básicas e agenda a primeira revisão para o dia seguinte.

### 3. Plano semanal e atividades

Ao concluir o diagnóstico, o plano inicial gera atividades para as semanas 1 a 4. Cada item possui:

- semana;
- data de liberação;
- prazo sugerido;
- competência;
- nível;
- tempo estimado;
- origem no plano do diagnóstico.

Atividades futuras aparecem como programadas e não podem ser abertas antes da data.

### 4. Áudio

- Áudios do WhatsApp são gravados em `voice_conversations` durante o salvamento da interação.
- Áudios antigos existentes em `messages` são recuperados pela migration.
- Áudios do portal são vinculados à mensagem de origem para evitar duplicidade no histórico.

### 5. Meta semanal

A meta padrão é calculada pelas preferências do aluno:

- minutos = minutos diários × dias por semana;
- atividades = quantidade de dias por semana, mínimo 2;
- palavras = dias por semana × 3, mínimo 5.

O aluno pode personalizar os três valores em `/portal/progress.php`. A plataforma mostra a origem da meta.

### 6. Tempo de estudo

O total combina:

- atividades concluídas na plataforma, com tempo real medido no navegador;
- turnos de conversa no portal;
- páginas ativas de estudo, como vocabulário, correções e diagnóstico;
- interações do WhatsApp;
- duração real dos áudios.

No WhatsApp, textos usam o intervalo entre mensagens do aluno, limitado entre 30 segundos e 5 minutos. A primeira mensagem da sessão recebe 60 segundos. Isso evita contar horas de inatividade.

### 7. Conquistas

As conquistas passam a ser liberadas automaticamente por diagnóstico, primeira conversa, primeiro áudio, primeira atividade, sequência, vocabulário, revisões e tempo acumulado.

## Instalação

1. Faça backup do PostgreSQL e do projeto.
2. Atualize os arquivos do projeto.
3. Execute `database/035_student_portal_learning_cycle.sql` no Adminer.
4. Faça o redeploy do serviço web.
5. No n8n, desative os fluxos antigos que usam os mesmos webhooks.
6. Importe e configure:
   - `docs/fluxos/rs-english-n8n-v18-whatsapp-ciclo-aluno.json`
   - `docs/fluxos/rs-english-n8n-v18-web-portal-ciclo-aluno.json`
   - `docs/fluxos/rs-english-n8n-v18-activity-evaluator.json`
7. Selecione a credencial OpenAI nos nós de IA e ative os novos fluxos.

## Teste recomendado

Use um aluno de teste e faça nesta ordem:

1. Abra o diagnóstico e confirme que o feedback não mostra `\\n`.
2. Envie uma frase nova para a Emma.
3. Confira se até 3 itens relevantes aparecem em `/portal/vocabulary.php`.
4. Envie um áudio pelo WhatsApp e outro pelo portal.
5. Confira o filtro Áudios em `/portal/history.php`.
6. Conclua uma atividade e compare tempo, nota e meta semanal.
7. Edite a meta semanal no painel de progresso.
8. Confira a separação entre minutos da plataforma e do WhatsApp.

## Observação sobre dados anteriores

A migration recupera feedbacks com quebras literais e áudios antigos. Vocabulário antigo só pode ser recuperado quando já existe uma avaliação estruturada contendo os itens; a plataforma não adivinha palavras históricas a partir de textos soltos.
