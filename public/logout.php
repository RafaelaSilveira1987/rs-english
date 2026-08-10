<?php
require_once __DIR__ . '/../src/auth.php';
logout_admin();
header('Location: /login.php');
exit;
