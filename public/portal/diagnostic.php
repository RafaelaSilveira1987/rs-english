<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/ui.php';
require_once __DIR__ . '/../../src/portal.php';

$user = require_student();
$studentId = (string)$user['student_id'];
$profile = portal_profile($studentId);
$diagnostic = portal_latest_diagnostic($studentId);
$plan = portal_active_plan($studentId);

$status = strtolower((string)($profile['diagnostic_status'] ?? 'pending'));
$diagnosticTotalSteps = 8;
$step = max(0, min($diagnosticTotalSteps, (int)($profile['diagnostic_step'] ?? 0)));
$complete = in_array($status, ['completed', 'complete', 'finished'], true);
$progress = $complete ? 100 : (int)round(($step / $diagnosticTotalSteps) * 100);

$scores = $diagnostic['scores'] ?? [];
if (!$scores) {
    $scores = [
        'grammar' => (float)($profile['grammar_score'] ?? 0),
        'vocabulary' => (float)($profile['vocabulary_score'] ?? 0),
        'interaction' => (float)($profile['speaking_score'] ?? 0),
        'reception' => (float)($profile['listening_score'] ?? 0),
        'production' => (float)($profile['writing_score'] ?? 0),
        'fluency' => (float)($profile['fluency_score'] ?? 0),
        'pronunciation' => (float)($profile['pronunciation_score'] ?? 0),
    ];
}

$scoreLabels = [
    'reception' => 'Compreensão',
    'interaction' => 'Interação',
    'production' => 'Produção',
    'grammar' => 'Gramática',
    'vocabulary' => 'Vocabulário',
    'fluency' => 'Fluência',
    'coherence' => 'Coerência',
    'pronunciation' => 'Pronúncia',
];

$strengths = $diagnostic['strengths'] ?? [];
$weaknesses = $diagnostic['weaknesses'] ?? [];
$recommendations = $diagnostic['recommendations'] ?? [];
$studyPlan = $diagnostic['study_plan'] ?? ($plan['plan_data'] ?? []);
$firstActivity = $diagnostic['first_activity'] ?? [];
$estimatedLevel = $diagnostic['estimated_level'] ?? $profile['estimated_level'] ?? $profile['overall_level'] ?? 'PRE-A1';
$confidence = $diagnostic['confidence_score'] ?? null;

$pageTitle = 'Meu diagnóstico';
$pageSubtitle = 'Veja seu nível estimado, evidências e plano inicial de desenvolvimento.';
require __DIR__ . '/../../templates/header.php';
?>

<section class="hero diagnostic-hero">
    <div class="hero-copy">
        <span class="badge dark <?= e(ui_level_class((string)$estimatedLevel)) ?>">
            Nível estimado <?= e((string)$estimatedLevel) ?>
        </span>
        <h2><?= $complete ? 'Seu diagnóstico inicial está concluído.' : 'Seu diagnóstico ainda está em andamento.' ?></h2>
        <p class="label">
            <?= $complete
                ? 'Resultado pedagógico baseado nas respostas registradas e alinhado aos descritores do QECR.'
                : 'Continue respondendo às atividades para que a Emma refine sua estimativa de nível.' ?>
        </p>
        <div class="hero-actions">
            <?php if (!$complete): ?>
                <a class="btn btn-primary" href="/portal/practice.php?mode=diagnostic">
                    <?= ui_icon('diagnostic', 'icon-sm') ?>
                    <?= $step > 0 ? 'Continuar diagnóstico' : 'Iniciar diagnóstico' ?>
                </a>
            <?php else: ?>
                <a class="btn btn-primary" href="/portal/practice.php">
                    <?= ui_icon('practice', 'icon-sm') ?> Continuar praticando
                </a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="/portal/progress.php">
                <?= ui_icon('progress', 'icon-sm') ?> Ver progresso
            </a>
        </div>
    </div>
    <div class="diagnostic-level-card">
        <span>Estimativa atual</span>
        <strong><?= e((string)$estimatedLevel) ?></strong>
        <small><?= $confidence !== null ? number_format((float)$confidence, 0) . '% de confiança' : 'Em refinamento contínuo' ?></small>
    </div>
</section>

<section class="panel diagnostic-progress-panel">
    <div class="panel-head">
        <div>
            <h2>Etapas do diagnóstico</h2>
            <p><?= $complete ? 'Todas as etapas principais foram registradas.' : 'Etapa ' . $step . ' de ' . $diagnosticTotalSteps . '.' ?></p>
        </div>
        <strong><?= $progress ?>%</strong>
    </div>
    <div class="progress progress-lg"><span data-progress="<?= $progress ?>"></span></div>
    <div class="diagnostic-steps">
        <?php
        $steps = [
            1 => ['Autoavaliação', 'Ponto de partida'],
            2 => ['Entrada', 'Primeira amostra'],
            3 => ['Compreensão', 'Entendimento contextual'],
            4 => ['Estrutura', 'Gramática em uso'],
            5 => ['Leitura', 'Compreensão de texto'],
            6 => ['Produção', 'Construção de frases'],
            7 => ['Interação', 'Coerência e autonomia'],
            8 => ['Síntese', 'Nível e plano inicial'],
        ];
        foreach ($steps as $number => [$title, $description]):
            $done = $complete || $step >= $number;
            $current = !$complete && $step === $number - 1;
        ?>
            <div class="diagnostic-step <?= $done ? 'done' : ($current ? 'current' : '') ?>">
                <span><?= $done ? '✓' : $number ?></span>
                <div><strong><?= e($title) ?></strong><small><?= e($description) ?></small></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($diagnostic || $complete): ?>
<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Competências observadas</h2><p>Leitura pedagógica das evidências coletadas.</p></div></div>
        <?php foreach ($scoreLabels as $key => $label):
            if (!array_key_exists($key, $scores) || $scores[$key] === null) continue;
            $score = max(0, min(100, (float)$scores[$key]));
        ?>
            <div class="skill">
                <div class="skill-head"><span><?= e($label) ?></span><strong><?= number_format($score, 0) ?>%</strong></div>
                <div class="progress"><span data-progress="<?= $score ?>"></span></div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Leitura do resultado</h2><p>Pontos fortes e prioridades para o próximo ciclo.</p></div></div>
        <div class="diagnostic-list-block">
            <h3>Pontos fortes</h3>
            <?php if ($strengths): ?>
                <ul class="check-list"><?php foreach ($strengths as $item): ?><li><?= e(portal_clean_text($item)) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
                <p class="muted">As evidências positivas aparecerão após a conclusão do diagnóstico.</p>
            <?php endif; ?>
        </div>
        <div class="diagnostic-list-block">
            <h3>Prioridades</h3>
            <?php if ($weaknesses): ?>
                <ul class="priority-list"><?php foreach ($weaknesses as $item): ?><li><?= e(portal_clean_text($item)) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
                <p class="muted">As prioridades serão definidas com base nas respostas.</p>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="panel section-gap">
    <div class="panel-head"><div><h2>Feedback da Emma</h2><p>Resumo do seu diagnóstico inicial.</p></div></div>
    <div class="teacher-feedback">
        <div class="avatar">E</div>
        <div>
            <strong>Emma</strong>
            <p><?= nl2br(e(portal_clean_text($diagnostic['written_feedback'] ?? 'Seu resultado será detalhado assim que todas as etapas forem concluídas.'))) ?></p>
        </div>
    </div>
</section>

<div class="grid-2 section-gap">
    <section class="panel">
        <div class="panel-head"><div><h2>Recomendações</h2><p>Ações indicadas para o seu momento atual.</p></div></div>
        <?php if ($recommendations): ?>
            <div class="stack">
                <?php foreach ($recommendations as $index => $item): ?>
                    <div class="list-card numbered-card"><span><?= $index + 1 ?></span><div><strong><?= e(portal_clean_text($item)) ?></strong></div></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state compact"><p>As recomendações serão exibidas depois da conclusão.</p></div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Primeiro passo</h2><p>Atividade sugerida para começar seu plano.</p></div></div>
        <div class="list-card featured-card">
            <span class="badge neutral">Atividade inicial</span>
            <strong><?= e((string)($firstActivity['title'] ?? 'Prática de apresentação')) ?></strong>
            <p><?= e(portal_clean_text($firstActivity['instruction'] ?? 'Converse com a Emma e faça uma apresentação curta sobre você e sua rotina.')) ?></p>
            <a class="btn btn-primary btn-sm" href="/portal/practice.php" style="margin-top:12px">Começar agora</a>
        </div>
    </section>
</div>

<?php if ($studyPlan): ?>
<section class="panel section-gap">
    <div class="panel-head"><div><h2>Plano inicial</h2><p>Organização sugerida para as próximas semanas.</p></div></div>
    <div class="plan-grid">
        <?php foreach (['week_1' => 'Semana 1', 'week_2' => 'Semana 2', 'week_3' => 'Semana 3', 'week_4' => 'Semana 4'] as $key => $label):
            $items = portal_json($studyPlan[$key] ?? [], []);
        ?>
            <article class="plan-week">
                <span><?= e($label) ?></span>
                <?php if ($items): ?><ul><?php foreach ($items as $item): ?><li><?= e(portal_clean_text($item)) ?></li><?php endforeach; ?></ul><?php else: ?><p>Etapas em preparação.</p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="notice section-gap">
    <?= ui_icon('shield', 'icon-sm') ?>
    <span>Este resultado é uma estimativa pedagógica alinhada ao QECR e não substitui uma certificação oficial.</span>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
