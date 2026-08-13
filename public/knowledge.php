<?php
declare(strict_types=1);

require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/auth.php';

require_teacher_or_admin();

$pdo=db();

$sources=$pdo->query("
SELECT *
FROM knowledge_sources
ORDER BY created_at DESC
")->fetchAll();

$pageTitle='Conteúdos';
require __DIR__.'/../templates/header.php';
?>

<section class="cards">
    <div class="card">
        <div class="label">Fontes cadastradas</div>
        <div class="metric"><?= count($sources) ?></div>
    </div>

    <div class="card">
        <div class="label">Indexadas</div>
        <div class="metric"><?= count(array_filter($sources,fn($x)=>($x['status'] ?? '')==='indexed')) ?></div>
    </div>

    <div class="card">
        <div class="label">Chunks</div>
        <div class="metric"><?= array_sum(array_map(fn($x)=>(int)($x['chunk_count'] ?? 0),$sources)) ?></div>
    </div>

    <div class="card">
        <div class="label">RAG</div>
        <div class="metric" style="font-size:21px">Ativo</div>
        <div class="metric-sub">Busca semântica pelo backend</div>
    </div>
</section>

<section class="panel">
<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px">
    <div>
        <h2 style="margin-bottom:4px">Biblioteca</h2>
        <div class="label">As telas completas de upload da v5 continuam compatíveis.</div>
    </div>

    <a class="btn btn-primary" href="/knowledge.php">Atualizar</a>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Fonte</th>
    <th>Nível</th>
    <th>Categoria</th>
    <th>Status</th>
    <th>Chunks</th>
</tr>
</thead>
<tbody>
<?php foreach($sources as $source): ?>
<tr>
    <td>
        <?php if(file_exists(__DIR__.'/knowledge-source.php')): ?>
            <a href="/knowledge-source.php?id=<?= urlencode($source['id']) ?>">
                <strong><?= htmlspecialchars($source['title']) ?></strong>
            </a>
        <?php else: ?>
            <strong><?= htmlspecialchars($source['title']) ?></strong>
        <?php endif; ?>
    </td>
    <td><span class="badge"><?= htmlspecialchars($source['level'] ?? 'Todos') ?></span></td>
    <td><?= htmlspecialchars($source['category'] ?? '-') ?></td>
    <td>
        <?php $class=($source['status'] ?? '')==='indexed'?'success':(($source['status'] ?? '')==='error'?'danger':'warning'); ?>
        <span class="badge <?= $class ?>"><?= htmlspecialchars($source['status'] ?? 'pending') ?></span>
    </td>
    <td><?= (int)($source['chunk_count'] ?? 0) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<?php require __DIR__.'/../templates/footer.php'; ?>
