<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_n8n_key();
$rows=db()->query("SELECT s.id,s.name,s.phone FROM students s WHERE s.status='active' AND s.phone IS NOT NULL ORDER BY s.name")->fetchAll();
json_response(['students'=>$rows]);
