<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';

require_admin();

$pdo=db();
$error=null;
$success=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $name=trim($_POST['name'] ?? '');
        $email=trim($_POST['email'] ?? '');
        $phone=preg_replace('/\D+/','',$_POST['phone'] ?? '');
        $username=trim($_POST['username'] ?? '');
        $role=trim($_POST['role'] ?? 'student');
        $password=$_POST['password'] ?? '';

        if($name==='' || $username==='' || strlen($password)<6){
            throw new RuntimeException('Nome, usuário e senha com pelo menos 6 caracteres são obrigatórios.');
        }

        $studentId=null;

        if($role==='student'){
            if(!$phone){
                throw new RuntimeException('Telefone é obrigatório para aluno.');
            }

            $find=$pdo->prepare("SELECT id FROM students WHERE phone=:phone LIMIT 1");
            $find->execute(['phone'=>$phone]);
            $studentId=$find->fetchColumn();

            if(!$studentId){
                $create=$pdo->prepare("
                INSERT INTO students(name,phone)
                VALUES(:name,:phone)
                RETURNING id
                ");
                $create->execute(['name'=>$name,'phone'=>$phone]);
                $studentId=$create->fetchColumn();

                $pdo->prepare("
                INSERT INTO student_profiles(
                    student_id,overall_level,goal,correction_mode,
                    diagnostic_status,diagnostic_step
                )
                VALUES(:id,'A1','Aprender inglês','balanced','pending',0)
                ")->execute(['id'=>$studentId]);
            }
        }

        $stmt=$pdo->prepare("
        INSERT INTO app_users(
            student_id,name,email,phone,username,password_hash,role,status
        )
        VALUES(
            :student_id,:name,:email,:phone,:username,:password_hash,:role,'active'
        )
        ");

        $stmt->execute([
            'student_id'=>$studentId,
            'name'=>$name,
            'email'=>$email ?: null,
            'phone'=>$phone ?: null,
            'username'=>$username,
            'password_hash'=>password_hash($password,PASSWORD_DEFAULT),
            'role'=>$role
        ]);

        $success='Usuário criado com sucesso.';
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$users=$pdo->query("
SELECT
    u.id,u.name,u.email,u.phone,u.username,u.role,u.status,u.last_login_at,
    s.id student_id
FROM app_users u
LEFT JOIN students s ON s.id=u.student_id
ORDER BY u.created_at DESC
")->fetchAll();

$pageTitle='Usuários';
require __DIR__.'/../../templates/header.php';
?>

<?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="list-card"><strong><?= htmlspecialchars($success) ?></strong></div><?php endif; ?>

<div class="grid-2">
<section class="panel">
    <h2>Novo usuário</h2>

    <form method="post">
        <div class="form-row">
            <label>Nome</label>
            <input name="name" required>
        </div>

        <div class="grid-2" style="grid-template-columns:1fr 1fr">
            <div class="form-row">
                <label>Usuário</label>
                <input name="username" required>
            </div>

            <div class="form-row">
                <label>Perfil</label>
                <select name="role">
                    <option value="student">Aluno</option>
                    <option value="teacher">Professor</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <label>E-mail</label>
            <input type="email" name="email">
        </div>

        <div class="form-row">
            <label>Telefone</label>
            <input name="phone" placeholder="5532...">
        </div>

        <div class="form-row">
            <label>Senha inicial</label>
            <input type="password" name="password" required>
        </div>

        <button class="btn btn-primary">Criar usuário</button>
    </form>
</section>

<section class="panel">
    <h2>Perfis</h2>
    <div class="list-card">
        <strong>Aluno</strong>
        <p>Acessa somente o próprio progresso, atividades, vocabulário e plano.</p>
    </div>
    <div class="list-card">
        <strong>Professor</strong>
        <p>Pode acompanhar alunos e conteúdos.</p>
    </div>
    <div class="list-card">
        <strong>Administrador</strong>
        <p>Gerencia usuários, plataforma e configurações.</p>
    </div>
</section>
</div>

<section class="panel" style="margin-top:20px">
    <h2>Usuários cadastrados</h2>
    <div class="table-wrap">
    <table>
        <thead>
        <tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Telefone</th><th>Status</th><th>Último login</th></tr>
        </thead>
        <tbody>
        <?php foreach($users as $u): ?>
        <tr>
            <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
            <td><?= htmlspecialchars($u['username'] ?? '-') ?></td>
            <td><span class="badge"><?= htmlspecialchars($u['role']) ?></span></td>
            <td><?= htmlspecialchars($u['phone'] ?? '-') ?></td>
            <td><span class="badge success"><?= htmlspecialchars($u['status']) ?></span></td>
            <td><?= $u['last_login_at'] ? date('d/m/Y H:i',strtotime($u['last_login_at'])) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php require __DIR__.'/../../templates/footer.php'; ?>
