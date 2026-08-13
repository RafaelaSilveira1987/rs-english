<?php
declare(strict_types=1);

require_once __DIR__.'/../../src/db.php';
require_once __DIR__.'/../../src/auth.php';

$user=require_student();

$pageTitle='Praticar com Emma';
require __DIR__.'/../../templates/header.php';
?>

<section class="panel voice-practice">
    <div class="practice-tabs">
        <button class="btn btn-primary" type="button" data-tab-button="text">
            ⌨️ Digitar
        </button>

        <button class="btn btn-secondary" type="button" data-tab-button="voice">
            🎙️ Conversar por áudio
        </button>
    </div>

    <div data-tab-panel="text">
        <div id="chat" class="voice-chat"></div>

        <form id="practice-form" class="practice-compose">
            <input
                id="message"
                autocomplete="off"
                placeholder="Escreva em inglês..."
                required
            >

            <button class="btn btn-primary">Enviar</button>
        </form>
    </div>

    <div data-tab-panel="voice" hidden>
        <div class="voice-recorder">
            <div class="voice-status">
                <span id="voice-dot" class="record-dot"></span>
                <strong id="voice-status-text">Pronto para gravar</strong>
                <span id="voice-timer" class="badge">00:00</span>
            </div>

            <div class="voice-actions">
                <button id="start-recording" class="btn btn-primary" type="button">
                    🎙️ Iniciar gravação
                </button>

                <button id="stop-recording" class="btn btn-secondary" type="button" disabled>
                    ⏹️ Parar
                </button>

                <button id="discard-recording" class="btn btn-secondary" type="button" disabled>
                    🗑️ Descartar
                </button>
            </div>

            <audio id="recording-preview" controls hidden></audio>

            <button id="send-recording" class="btn btn-primary" type="button" disabled>
                Enviar para Emma
            </button>
        </div>

        <div id="voice-result" hidden>
            <div class="list-card">
                <strong>Você disse</strong>
                <p id="student-transcription"></p>
            </div>

            <div class="list-card">
                <strong>Emma respondeu</strong>
                <p id="teacher-response"></p>
                <audio id="teacher-audio" controls></audio>
            </div>
        </div>
    </div>
</section>

<script src="/assets/js/voice-practice.js"></script>

<?php require __DIR__.'/../../templates/footer.php'; ?>
