<?php
/** POST logoutdaniel.php — encerra a sessão no servidor. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();
$_SESSION = [];
session_destroy();
echo json_encode(['sucesso' => true]);