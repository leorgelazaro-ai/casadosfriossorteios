<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
startAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

$config = initConfig();
if (!password_verify($password, $config['admin_password_hash'])) {
    jsonResponse(['error' => 'Senha incorreta'], 401);
}

$_SESSION['admin_logged_in'] = true;
jsonResponse(['ok' => true, 'message' => 'Login realizado']);
