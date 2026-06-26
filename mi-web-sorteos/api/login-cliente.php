<?php
require_once __DIR__ . '/lib/store.php';
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
$senha = $input['senha'] ?? '';

if (strlen($cpf) !== 11 || !$senha) {
    jsonResponse(['error' => 'Informe CPF e senha'], 400);
}

initConfig();

try {
    $usuario = loginCliente($cpf, $senha);
    jsonResponse(['ok' => true, 'usuario' => $usuario]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 401);
}
