<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';

$user=require_student();
$pdo=db();
$studentId=$user['student_id'];

$stmt=$pdo->prepare("SELECT * FROM student_preferences WHERE student_id=:id LIMIT 1");
$stmt->execute(['id'=>$studentId]);
$prefs=$stmt->fetch();

if(!$prefs){
    $pdo->prepare("
    INSERT INTO student_preferences(student_id)
    VALUES(:id)
    ")->execute(['id'=>$studentId]);

    $stmt->execute(['id'=>$studentId]);
    $prefs=$stmt->fetch();
}

$success=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $topics=array_values(array_filter(array_map('trim',explode(',',$_POST['preferred_topics'] ?? ''))));

    $pdo->prepare("
    UPDATE student_preferences SET
        daily_minutes=:daily_minutes,
        weekly_days=:weekly_days,
        preferred_topics=CAST(:topics AS jsonb),
        focus_mode=:focus_mode,
        correction_mode=:correction_mode,
        preferred_study_time=:preferred_study_time,
        notes=:notes,
        updated_at=NOW()
    WHERE student_id=:id
    ")->execute([
        'daily_minutes'=>(int)($_POST['daily_minutes'] ?? 20),
        'weekly_days'=>(int)($_POST['weekly_days'] ?? 5),
        'topics'=>json_encode($topics,JSON_UNESCAPED_UNICODE),
        'focus_mode'=>$_POST['focus_mode'] ?? 'conversation',
        'correction_mode'=>$_POST['correction_mode'] ?? 'balanced',
        'preferred_study_time'=>trim($_POST['preferred_study_time'] ?? '') ?: null,
        'notes'=>trim($_POST['notes'] ?? '') ?: null,
        'id'=>$studentId
    ]);

    $pdo->prepare("
    UPDATE student_profiles SET
        correction_mode=:correction,
        updated_at=NOW()
    WHERE student_id=:id
    ")->execute([
        'correction'=>$_POST['correction_mode'] ?? 'balanced',
        'id'=>$studentId
    ]);

    $success='Preferências salvas.';

    $stmt->execute(['id'=>$studentId]);
    $prefs=$stmt->fetch();
}

$topics=implode(', ',json_decode($prefs['preferred_topics'] ?? '[]',true) ?: []);

$pageTitle='Meu plano de estudo';
require __DIR__.'/../../templates/header.php';
?>

<?php if($success): ?><div class="list-card"><strong><?= htmlspecialchars($success) ?></strong></div><?php endif; ?>

<section class="panel">
<form method="post">
    <div class="grid-2" style="grid-template-columns:1fr 1fr">
        <div class="form-row">
            <label>Minutos por dia</label>
            <input type="number" min="5" max="180" name="daily_minutes"
                   value="<?= (int)$prefs['daily_minutes'] ?>">
        </div>

        <div class="form-row">
            <label>Dias por semana</label>
            <input type="number" min="1" max="7" name="weekly_days"
                   value="<?= (int)$prefs['weekly_days'] ?>">
        </div>
    </div>

    <div class="form-row">
        <label>Temas que você gosta</label>
        <input name="preferred_topics" value="<?= htmlspecialchars($topics) ?>"
               placeholder="tecnologia, viagem, trabalho, séries...">
    </div>

    <div class="grid-2" style="grid-template-columns:1fr 1fr">
        <div class="form-row">
            <label>Foco principal</label>
            <select name="focus_mode">
                <?php foreach([
                    'conversation'=>'Conversação',
                    'grammar'=>'Gramática',
                    'vocabulary'=>'Vocabulário',
                    'business'=>'Business English',
                    'travel'=>'Viagem'
                ] as $v=>$label): ?>
                    <option value="<?= $v ?>" <?= $prefs['focus_mode']===$v?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>Correção</label>
            <select name="correction_mode">
                <?php foreach(['light','balanced','intensive'] as $v): ?>
                    <option value="<?= $v ?>" <?= $prefs['correction_mode']===$v?'selected':'' ?>>
                        <?= ucfirst($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-row">
        <label>Melhor horário para estudar</label>
        <input name="preferred_study_time"
               value="<?= htmlspecialchars($prefs['preferred_study_time'] ?? '') ?>"
               placeholder="Ex.: noite, 20h">
    </div>

    <div class="form-row">
        <label>Observações</label>
        <textarea name="notes" rows="5"><?= htmlspecialchars($prefs['notes'] ?? '') ?></textarea>
    </div>

    <button class="btn btn-primary">Salvar</button>
</form>
</section>

<?php require __DIR__.'/../../templates/footer.php'; ?>
