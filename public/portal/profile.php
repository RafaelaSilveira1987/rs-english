<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$pdo = db();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $section = trim((string)($_POST['section'] ?? 'personal'));
        $pdo->beginTransaction();

        if ($section === 'personal') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            if ($name === '') throw new RuntimeException('Nome obrigatório.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');

            $pdo->prepare('UPDATE app_users SET name = :name, email = :email, updated_at = NOW() WHERE id = :id')
                ->execute(['name' => $name, 'email' => $email ?: null, 'id' => $user['id']]);
            $pdo->prepare('UPDATE students SET name = :name, email = :email, updated_at = NOW() WHERE id = :id')
                ->execute(['name' => $name, 'email' => $email ?: null, 'id' => $user['student_id']]);
            $success = 'Dados pessoais atualizados.';
        } else {
            $dailyMinutes = max(5, min(180, (int)($_POST['daily_minutes'] ?? 20)));
            $weeklyDays = max(1, min(7, (int)($_POST['weekly_days'] ?? 5)));
            $focusMode = in_array($_POST['focus_mode'] ?? '', ['conversation', 'grammar', 'vocabulary', 'balanced'], true) ? $_POST['focus_mode'] : 'conversation';
            $correctionMode = in_array($_POST['correction_mode'] ?? '', ['light', 'balanced', 'intensive'], true) ? $_POST['correction_mode'] : 'balanced';
            $language = in_array($_POST['explanations_language'] ?? '', ['adaptive', 'portuguese', 'english'], true) ? $_POST['explanations_language'] : 'adaptive';
            $responseMode = in_array($_POST['response_mode'] ?? '', ['automatic', 'text', 'audio'], true) ? $_POST['response_mode'] : 'automatic';
            $voiceName = in_array($_POST['voice_name'] ?? '', ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse'], true) ? $_POST['voice_name'] : 'coral';
            $voiceSpeed = max(0.75, min(1.35, (float)($_POST['voice_speed'] ?? 1)));
            $conversationTopic = trim((string)($_POST['conversation_topic'] ?? 'daily_life')) ?: 'daily_life';
            $conversationStyle = in_array($_POST['conversation_style'] ?? '', ['guided', 'free', 'roleplay'], true) ? $_POST['conversation_style'] : 'guided';
            $conversationMaxTurns = max(4, min(30, (int)($_POST['conversation_max_turns'] ?? 10)));
            $preferredStudyTime = trim((string)($_POST['preferred_study_time'] ?? '')) ?: null;
            $reminderEnabled = isset($_POST['reminder_enabled']);
            $reminderTime = trim((string)($_POST['reminder_time'] ?? '')) ?: null;
            $autoplay = isset($_POST['autoplay_audio']);
            $showTranscription = isset($_POST['show_transcription']);

            $stmt = $pdo->prepare(<<<'SQL'
                INSERT INTO student_preferences(
                    student_id, daily_minutes, weekly_days, focus_mode,
                    correction_mode, explanations_language, response_mode,
                    voice_name, voice_speed, autoplay_audio, show_transcription,
                    conversation_topic, conversation_style, conversation_max_turns,
                    interface_language, reminder_enabled, reminder_time,
                    preferred_study_time, updated_at
                ) VALUES(
                    :student_id, :daily_minutes, :weekly_days, :focus_mode,
                    :correction_mode, :explanations_language, :response_mode,
                    :voice_name, :voice_speed, CAST(:autoplay_audio AS boolean), CAST(:show_transcription AS boolean),
                    :conversation_topic, :conversation_style, :conversation_max_turns,
                    'pt-BR', CAST(:reminder_enabled AS boolean), :reminder_time,
                    :preferred_study_time, NOW()
                )
                ON CONFLICT(student_id)
                DO UPDATE SET
                    daily_minutes = EXCLUDED.daily_minutes,
                    weekly_days = EXCLUDED.weekly_days,
                    focus_mode = EXCLUDED.focus_mode,
                    correction_mode = EXCLUDED.correction_mode,
                    explanations_language = EXCLUDED.explanations_language,
                    response_mode = EXCLUDED.response_mode,
                    voice_name = EXCLUDED.voice_name,
                    voice_speed = EXCLUDED.voice_speed,
                    autoplay_audio = EXCLUDED.autoplay_audio,
                    show_transcription = EXCLUDED.show_transcription,
                    conversation_topic = EXCLUDED.conversation_topic,
                    conversation_style = EXCLUDED.conversation_style,
                    conversation_max_turns = EXCLUDED.conversation_max_turns,
                    interface_language = EXCLUDED.interface_language,
                    reminder_enabled = EXCLUDED.reminder_enabled,
                    reminder_time = EXCLUDED.reminder_time,
                    preferred_study_time = EXCLUDED.preferred_study_time,
                    updated_at = NOW()
            SQL);
            $stmt->execute([
                'student_id' => $user['student_id'],
                'daily_minutes' => $dailyMinutes,
                'weekly_days' => $weeklyDays,
                'focus_mode' => $focusMode,
                'correction_mode' => $correctionMode,
                'explanations_language' => $language,
                'response_mode' => $responseMode,
                'voice_name' => $voiceName,
                'voice_speed' => $voiceSpeed,
                'autoplay_audio' => $autoplay ? 'true' : 'false',
                'show_transcription' => $showTranscription ? 'true' : 'false',
                'conversation_topic' => $conversationTopic,
                'conversation_style' => $conversationStyle,
                'conversation_max_turns' => $conversationMaxTurns,
                'reminder_enabled' => $reminderEnabled ? 'true' : 'false',
                'reminder_time' => $reminderTime,
                'preferred_study_time' => $preferredStudyTime,
            ]);

            $pdo->prepare('UPDATE student_profiles SET correction_mode = :mode, updated_at = NOW() WHERE student_id = :student_id')
                ->execute(['mode' => $correctionMode, 'student_id' => $user['student_id']]);
            $success = 'Preferências de aprendizagem atualizadas.';
        }

        $pdo->commit();
        audit_log('student_profile_updated', 'student', (string)$user['student_id']);
        portal_record_event((string)$user['student_id'], 'profile', 'Preferências atualizadas', $success);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$dataStmt = $pdo->prepare(<<<'SQL'
    SELECT
        u.name, u.email, u.phone, u.username, u.last_login_at,
        s.created_at AS student_since,
        COALESCE(sp.overall_level, 'PRE-A1') AS overall_level,
        COALESCE(sp.xp, 0) AS xp
    FROM app_users u
    JOIN students s ON s.id = u.student_id
    LEFT JOIN student_profiles sp ON sp.student_id = s.id
    WHERE u.id = :id
SQL);
$dataStmt->execute(['id' => $user['id']]);
$data = $dataStmt->fetch();
$profile = portal_profile((string)$user['student_id']);

$pageTitle = 'Meu perfil';
$pageSubtitle = 'Gerencie seus dados, preferências pedagógicas, voz e rotina de estudos.';
require __DIR__ . '/../../templates/header.php';
?>

<?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

<section class="student-head">
    <div style="display:flex;align-items:center;gap:16px">
        <div class="avatar avatar-lg"><?= e(ui_initials((string)$data['name'])) ?></div>
        <div><span class="badge dark"><?= e((string)$data['overall_level']) ?></span><h2><?= e((string)$data['name']) ?></h2><div class="label">Aluno desde <?= e(ui_date_only((string)$data['student_since'])) ?></div></div>
    </div>
    <div class="hero-stat"><strong><?= (int)$data['xp'] ?> XP</strong><span>progresso acumulado</span></div>
</section>

<div class="grid-2 equal">
    <section class="panel">
        <div class="panel-head"><div><h2>Dados pessoais</h2><p>Nome e e-mail aparecem no portal e nos relatórios.</p></div></div>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="section" value="personal">
            <div class="form-row"><label>Nome</label><input name="name" value="<?= e((string)$data['name']) ?>" required></div>
            <div class="form-row"><label>E-mail</label><input type="email" name="email" value="<?= e((string)($data['email'] ?? '')) ?>"></div>
            <div class="form-row"><label>Telefone</label><input value="<?= e((string)($data['phone'] ?? '')) ?>" disabled><small class="form-help">O telefone está vinculado à integração do WhatsApp.</small></div>
            <div class="form-actions"><button class="btn btn-primary">Salvar dados</button></div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Acesso e segurança</h2><p>Informações vinculadas ao seu usuário.</p></div></div>
        <div class="info-grid">
            <div class="info-item"><span>Usuário</span><strong><?= e((string)($data['username'] ?? '—')) ?></strong></div>
            <div class="info-item"><span>Último acesso</span><strong><?= e(ui_date((string)($data['last_login_at'] ?? ''))) ?></strong></div>
        </div>
        <div class="list-card" style="margin-top:14px"><strong>Senha</strong><p>Atualize sua senha periodicamente e não compartilhe seu acesso.</p><a class="btn btn-secondary btn-sm" href="/change-password.php" style="margin-top:10px">Alterar senha</a></div>
    </section>
</div>

<form method="post" class="section-gap">
    <?= csrf_field() ?><input type="hidden" name="section" value="preferences">
    <div class="grid-2 equal">
        <section class="panel">
            <div class="panel-head"><div><h2>Preferências pedagógicas</h2><p>Defina o ritmo e a forma como a Emma deve acompanhar você.</p></div></div>
            <div class="grid-2 form-grid-2">
                <div class="form-row"><label>Minutos por dia</label><input type="number" name="daily_minutes" min="5" max="180" value="<?= (int)$profile['daily_minutes'] ?>"></div>
                <div class="form-row"><label>Dias por semana</label><input type="number" name="weekly_days" min="1" max="7" value="<?= (int)$profile['weekly_days'] ?>"></div>
            </div>
            <div class="form-row"><label>Foco principal</label><select name="focus_mode"><?php foreach (['conversation'=>'Conversação','grammar'=>'Gramática','vocabulary'=>'Vocabulário','balanced'=>'Equilibrado'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $profile['focus_mode']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label>Modo de correção</label><select name="correction_mode"><option value="light" <?= $profile['correction_mode']==='light'?'selected':'' ?>>Leve — somente erros importantes</option><option value="balanced" <?= $profile['correction_mode']==='balanced'?'selected':'' ?>>Equilibrado — principais correções</option><option value="intensive" <?= $profile['correction_mode']==='intensive'?'selected':'' ?>>Intensivo — correção detalhada</option></select></div>
            <div class="form-row"><label>Idioma das explicações</label><select name="explanations_language"><option value="adaptive" <?= $profile['explanations_language']==='adaptive'?'selected':'' ?>>Adaptativo ao nível</option><option value="portuguese" <?= $profile['explanations_language']==='portuguese'?'selected':'' ?>>Principalmente português</option><option value="english" <?= $profile['explanations_language']==='english'?'selected':'' ?>>Principalmente inglês</option></select></div>
            <div class="form-row"><label>Horário preferido</label><select name="preferred_study_time"><option value="" <?= empty($profile['preferred_study_time'])?'selected':'' ?>>Sem preferência</option><option value="morning" <?= $profile['preferred_study_time']==='morning'?'selected':'' ?>>Manhã</option><option value="afternoon" <?= $profile['preferred_study_time']==='afternoon'?'selected':'' ?>>Tarde</option><option value="evening" <?= $profile['preferred_study_time']==='evening'?'selected':'' ?>>Noite</option></select></div>
        </section>

        <section class="panel">
            <div class="panel-head"><div><h2>Conversação e voz</h2><p>Personalize tema, duração e respostas em áudio.</p></div></div>
            <div class="form-row"><label>Tema padrão</label><select name="conversation_topic"><?php foreach (['daily_life'=>'Rotina e dia a dia','work'=>'Trabalho e carreira','technology'=>'Tecnologia','travel'=>'Viagem','food'=>'Comida e restaurante','movies'=>'Filmes e séries','goals'=>'Planos e objetivos','job_interview'=>'Entrevista de emprego','free_conversation'=>'Conversação livre'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $profile['conversation_topic']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="grid-2 form-grid-2">
                <div class="form-row"><label>Formato</label><select name="conversation_style"><option value="guided" <?= $profile['conversation_style']==='guided'?'selected':'' ?>>Guiada</option><option value="free" <?= $profile['conversation_style']==='free'?'selected':'' ?>>Livre</option><option value="roleplay" <?= $profile['conversation_style']==='roleplay'?'selected':'' ?>>Simulação</option></select></div>
                <div class="form-row"><label>Interações</label><select name="conversation_max_turns"><?php foreach ([6,10,14,20] as $value): ?><option value="<?= $value ?>" <?= (int)$profile['conversation_max_turns']===$value?'selected':'' ?>><?= $value ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="grid-2 form-grid-2">
                <div class="form-row"><label>Modo de resposta</label><select name="response_mode"><option value="automatic" <?= $profile['response_mode']==='automatic'?'selected':'' ?>>Automático</option><option value="text" <?= $profile['response_mode']==='text'?'selected':'' ?>>Somente texto</option><option value="audio" <?= $profile['response_mode']==='audio'?'selected':'' ?>>Priorizar áudio</option></select></div>
                <div class="form-row"><label>Voz da Emma</label><select name="voice_name"><?php foreach (['coral','nova','sage','shimmer','alloy','echo','verse'] as $voice): ?><option value="<?= e($voice) ?>" <?= $profile['voice_name']===$voice?'selected':'' ?>><?= e(ucfirst($voice)) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row"><label>Velocidade da voz</label><input type="range" name="voice_speed" min="0.75" max="1.35" step="0.05" value="<?= e((string)$profile['voice_speed']) ?>"><small class="form-help">Atual: <?= number_format((float)$profile['voice_speed'], 2) ?>x</small></div>
            <label class="toggle-row"><input type="checkbox" name="autoplay_audio" <?= $profile['autoplay_audio']?'checked':'' ?>><span><strong>Reproduzir áudio automaticamente</strong><small>Inicia a resposta da Emma assim que estiver pronta.</small></span></label>
            <label class="toggle-row"><input type="checkbox" name="show_transcription" <?= $profile['show_transcription']?'checked':'' ?>><span><strong>Mostrar transcrição</strong><small>Exibe o texto reconhecido no seu áudio.</small></span></label>
        </section>
    </div>

    <section class="panel section-gap">
        <div class="panel-head"><div><h2>Lembrete de estudo</h2><p>Deixe a preferência preparada para as notificações da plataforma.</p></div></div>
        <div class="reminder-settings">
            <label class="toggle-row"><input type="checkbox" name="reminder_enabled" <?= $profile['reminder_enabled']?'checked':'' ?>><span><strong>Ativar lembrete</strong><small>A rotina de envio será conectada ao fluxo de notificações.</small></span></label>
            <div class="form-row"><label>Horário</label><input type="time" name="reminder_time" value="<?= e(substr((string)($profile['reminder_time'] ?? ''),0,5)) ?>"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Salvar preferências</button></div>
    </section>
</form>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
