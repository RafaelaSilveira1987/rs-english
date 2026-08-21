<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$mode = ($_GET['mode'] ?? '') === 'diagnostic' ? 'diagnostic' : 'conversation';
$profile = portal_profile((string)$user['student_id']);

$active = $pdo->prepare(<<<'SQL'
    SELECT
        COALESCE(conversation_topic, topic, 'daily_life') AS topic,
        COALESCE(conversation_style, 'guided') AS style,
        COALESCE(turn_count, 0) AS turn_count,
        COALESCE(max_turns, 10) AS max_turns,
        status
    FROM sessions
    WHERE student_id = :student_id
      AND status = 'active'
      AND mode = :mode
    ORDER BY created_at DESC
    LIMIT 1
SQL);
$active->execute(['student_id' => $user['student_id'], 'mode' => $mode]);
$session = $active->fetch() ?: null;

$recentStmt = $pdo->prepare(<<<'SQL'
    SELECT role, content, transcription, message_type, created_at
    FROM messages
    WHERE student_id = :student_id
    ORDER BY created_at DESC
    LIMIT 12
SQL);
$recentStmt->execute(['student_id' => $user['student_id']]);
$recentMessages = array_reverse($recentStmt->fetchAll());

$topic = $session['topic'] ?? $profile['conversation_topic'] ?? 'daily_life';
$style = $session['style'] ?? $profile['conversation_style'] ?? 'guided';
$maxTurns = (int)($session['max_turns'] ?? $profile['conversation_max_turns'] ?? 10);
$correction = $profile['correction_mode'] ?? 'balanced';
$diagnosticTotalSteps = 8;
$diagnosticStep = max(0, min($diagnosticTotalSteps, (int)($profile['diagnostic_step'] ?? 0)));

$pageTitle = $mode === 'diagnostic' ? 'Diagnóstico com Emma' : 'Praticar com Emma';
$pageSubtitle = $mode === 'diagnostic'
    ? 'Responda uma etapa por vez para receber sua estimativa de nível e plano inicial.'
    : 'Escolha o tema e pratique por texto ou áudio.';
require __DIR__ . '/../../templates/header.php';
?>

<?php if ($mode === 'diagnostic'): ?>
<div class="diagnostic-mode-banner">
    <div><?= ui_icon('diagnostic') ?><span><strong>Modo diagnóstico</strong><small>Etapa <?= $diagnosticStep ?> de <?= $diagnosticTotalSteps ?> · responda com naturalidade, sem usar tradutor.</small></span></div>
    <a class="btn btn-secondary btn-sm" href="/portal/diagnostic.php">Ver diagnóstico</a>
</div>
<?php endif; ?>

<section class="practice-shell" data-practice-mode="<?= e($mode) ?>">
    <div class="practice-main">
        <section class="panel voice-practice">
            <div class="conversation-settings <?= $mode === 'diagnostic' ? 'diagnostic-settings' : '' ?>">
                <?php if ($mode === 'conversation'): ?>
                    <div><label for="conversation-topic">Tema</label><select id="conversation-topic"><?php foreach (['daily_life'=>'Rotina e dia a dia','work'=>'Trabalho e carreira','technology'=>'Tecnologia','travel'=>'Viagem','food'=>'Comida e restaurante','movies'=>'Filmes e séries','goals'=>'Planos e objetivos','job_interview'=>'Entrevista de emprego','free_conversation'=>'Conversação livre'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $topic===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div><label for="conversation-style">Formato</label><select id="conversation-style"><option value="guided" <?= $style==='guided'?'selected':'' ?>>Guiada</option><option value="free" <?= $style==='free'?'selected':'' ?>>Livre</option><option value="roleplay" <?= $style==='roleplay'?'selected':'' ?>>Simulação</option></select></div>
                    <div><label for="correction-mode">Correções</label><select id="correction-mode"><option value="light" <?= $correction==='light'?'selected':'' ?>>Leves</option><option value="balanced" <?= $correction==='balanced'?'selected':'' ?>>Equilibradas</option><option value="intensive" <?= $correction==='intensive'?'selected':'' ?>>Intensivas</option></select></div>
                    <div><label for="conversation-max-turns">Duração</label><select id="conversation-max-turns"><?php foreach ([6,10,14,20] as $n): ?><option value="<?= $n ?>" <?= $maxTurns===$n?'selected':'' ?>><?= $n ?> interações</option><?php endforeach; ?></select></div>
                <?php else: ?>
                    <input type="hidden" id="conversation-topic" value="initial_diagnostic">
                    <input type="hidden" id="conversation-style" value="guided">
                    <input type="hidden" id="correction-mode" value="balanced">
                    <input type="hidden" id="conversation-max-turns" value="10">
                    <div class="diagnostic-setting-copy"><span><?= ui_icon('target', 'icon-sm') ?></span><div><strong>Uma atividade por vez</strong><small>A Emma ajusta a dificuldade com base nas suas respostas.</small></div></div>
                    <div class="diagnostic-setting-copy"><span><?= ui_icon('shield', 'icon-sm') ?></span><div><strong>Resultado estimado</strong><small>O nível é uma referência pedagógica alinhada ao QECR.</small></div></div>
                <?php endif; ?>
            </div>

            <p class="conversation-hint"><?= $mode === 'diagnostic' ? 'Você pode responder por texto ou áudio. A amostra de áudio ajuda a observar pronúncia e fluência.' : 'A Emma corrige em português quando necessário e mantém a continuidade da conversa em inglês.' ?></p>

            <div class="practice-tabs">
                <button class="btn btn-primary" type="button" data-tab-button="text">Digitar</button>
                <button class="btn btn-secondary" type="button" data-tab-button="voice">Conversar por áudio</button>
            </div>

            <div data-tab-panel="text">
                <div id="chat" class="voice-chat">
                    <?php if ($recentMessages): ?>
                        <?php foreach ($recentMessages as $message):
                            $who = $message['role'] === 'teacher' ? 'teacher' : 'student';
                            $text = trim((string)($message['content'] ?: $message['transcription'] ?: ''));
                            if ($text === '') continue;
                        ?>
                            <div class="chat-message <?= e($who) ?>"><strong><?= $who === 'teacher' ? 'Emma' : 'Você' ?></strong><p><?= nl2br(e($text)) ?></p><small><?= e(ui_date((string)$message['created_at'])) ?></small></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="chat-message teacher"><strong>Emma</strong><p><?= $mode === 'diagnostic' ? 'Olá! Vamos começar seu diagnóstico inicial. Envie uma mensagem para iniciarmos a primeira etapa.' : 'Hello, ' . e(ui_first_name((string)$user['name'])) . '! Choose a topic and send your first message.' ?></p></div>
                    <?php endif; ?>
                </div>
                <div id="chat-typing" class="chat-typing" hidden><span></span><span></span><span></span><small>Emma está preparando a resposta...</small></div>
                <form id="practice-form" class="practice-compose">
                    <textarea id="message" rows="2" autocomplete="off" placeholder="<?= $mode === 'diagnostic' ? 'Digite sua resposta...' : 'Escreva em inglês...' ?>" required></textarea>
                    <button class="btn btn-primary">Enviar</button>
                </form>
            </div>

            <div data-tab-panel="voice" hidden>
                <div class="voice-recorder">
                    <div class="voice-status"><span id="voice-dot" class="record-dot"></span><strong id="voice-status-text">Pronto para gravar</strong><span id="voice-timer" class="badge">00:00</span></div>
                    <div class="voice-actions"><button id="start-recording" class="btn btn-primary" type="button">Iniciar gravação</button><button id="stop-recording" class="btn btn-secondary" type="button" disabled>Parar</button><button id="discard-recording" class="btn btn-secondary" type="button" disabled>Descartar</button></div>
                    <audio id="recording-preview" controls hidden></audio>
                    <button id="send-recording" class="btn btn-primary" type="button" disabled>Enviar para Emma</button>
                </div>
                <div id="voice-result" hidden>
                    <div class="list-card"><strong>Você disse</strong><p id="student-transcription"></p></div>
                    <div class="list-card"><strong>Emma respondeu</strong><p id="teacher-response"></p><audio id="teacher-audio" controls></audio></div>
                </div>
            </div>
        </section>
    </div>

    <aside class="practice-sidebar">
        <section class="panel">
            <h2><?= $mode === 'diagnostic' ? 'Seu diagnóstico' : 'Sua prática' ?></h2>
            <div class="info-grid">
                <div class="info-item"><span>Nível</span><strong><?= e((string)($profile['overall_level'] ?? 'PRE-A1')) ?></strong></div>
                <div class="info-item"><span><?= $mode === 'diagnostic' ? 'Etapa' : 'Correção' ?></span><strong><?= $mode === 'diagnostic' ? $diagnosticStep . '/5' : e(ui_status_label((string)$correction)) ?></strong></div>
                <div class="info-item"><span>Interações</span><strong><?= (int)($session['turn_count'] ?? 0) ?>/<?= $maxTurns ?></strong></div>
                <div class="info-item"><span>Canal</span><strong>Web</strong></div>
            </div>
        </section>
        <section class="panel">
            <h2>Dicas</h2>
            <div class="list-card"><strong>Responda com naturalidade</strong><p>Frases simples e sinceras ajudam a Emma a ajustar a próxima pergunta.</p></div>
            <div class="list-card"><strong>Peça ajuda quando precisar</strong><p>Escreva “não entendi” e a Emma explicará em português.</p></div>
            <div class="list-card"><strong>Use o áudio</strong><p>Pratique ritmo e naturalidade sem se preocupar com perfeição.</p></div>
        </section>
    </aside>
</section>

<script src="/assets/js/voice-practice.js?v=11.0"></script>
<?php require __DIR__ . '/../../templates/footer.php'; ?>
