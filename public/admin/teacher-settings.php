<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';

require_admin();

$pdo=db();
$success=null;

$stmt=$pdo->query("
SELECT *
FROM teacher_settings
ORDER BY created_at
LIMIT 1
");
$settings=$stmt->fetch();

if(!$settings){
    $pdo->exec("
    INSERT INTO teacher_settings(
        teacher_name,teacher_personality,default_correction_mode,
        default_language_mix,max_corrections_per_message
    )
    VALUES('Emma','balanced','balanced','adaptive',2)
    ");
    $settings=$pdo->query("SELECT * FROM teacher_settings ORDER BY created_at LIMIT 1")->fetch();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $pdo->prepare("
    UPDATE teacher_settings SET
        teacher_name=:name,
        teacher_personality=:personality,
        default_correction_mode=:correction,
        default_language_mix=:language_mix,
        max_corrections_per_message=:max_corrections,
        updated_at=NOW()
    WHERE id=:id
    ")->execute([
        'name'=>trim($_POST['teacher_name'] ?? 'Emma'),
        'personality'=>$_POST['teacher_personality'] ?? 'balanced',
        'correction'=>$_POST['default_correction_mode'] ?? 'balanced',
        'language_mix'=>$_POST['default_language_mix'] ?? 'adaptive',
        'max_corrections'=>(int)($_POST['max_corrections_per_message'] ?? 2),
        'id'=>$settings['id']
    ]);

    $success='Configurações atualizadas.';
    $settings=$pdo->query("SELECT * FROM teacher_settings ORDER BY created_at LIMIT 1")->fetch();
}

$pageTitle='Configurações do professor';
require __DIR__.'/../../templates/header.php';
?>

<?php if($success): ?><div class="list-card"><strong><?= htmlspecialchars($success) ?></strong></div><?php endif; ?>

<section class="panel">
<form method="post">
    <div class="grid-2" style="grid-template-columns:1fr 1fr">
        <div class="form-row">
            <label>Nome do professor IA</label>
            <input name="teacher_name" value="<?= htmlspecialchars($settings['teacher_name']) ?>">
        </div>

        <div class="form-row">
            <label>Personalidade</label>
            <select name="teacher_personality">
                <?php foreach(['supportive'=>'Acolhedora','balanced'=>'Equilibrada','strict'=>'Exigente'] as $v=>$label): ?>
                    <option value="<?= $v ?>" <?= $settings['teacher_personality']===$v?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="grid-2" style="grid-template-columns:1fr 1fr">
        <div class="form-row">
            <label>Correção padrão</label>
            <select name="default_correction_mode">
                <?php foreach(['light','balanced','intensive'] as $v): ?>
                    <option value="<?= $v ?>" <?= $settings['default_correction_mode']===$v?'selected':'' ?>><?= ucfirst($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>Idioma das explicações</label>
            <select name="default_language_mix">
                <?php foreach(['english'=>'Inglês','adaptive'=>'Adaptativo','portuguese_support'=>'Inglês + apoio PT'] as $v=>$label): ?>
                    <option value="<?= $v ?>" <?= $settings['default_language_mix']===$v?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-row">
        <label>Máximo de correções por mensagem</label>
        <input type="number" min="1" max="5" name="max_corrections_per_message"
               value="<?= (int)$settings['max_corrections_per_message'] ?>">
    </div>

    <button class="btn btn-primary">Salvar configurações</button>
</form>
</section>

<?php require __DIR__.'/../../templates/footer.php'; ?>
