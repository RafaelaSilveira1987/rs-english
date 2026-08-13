<?php
require_once __DIR__.'/../src/auth.php';
logout_user();
header('Location:/login.php');
exit;
