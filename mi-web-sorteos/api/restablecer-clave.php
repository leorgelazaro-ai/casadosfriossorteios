<?php
require_once __DIR__ . '/lib/store.php';
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
$email = trim($input['email'] ?? '');
$whatsapp = trim($input['whatsapp'] ?? '');
$senha = $input['senha'] ?? '';
$senha2 = $input['senha_confirm'] ?? $senha;

if (strlen($cpf) !== 11 || !$email || !$whatsapp || !$senha) {
    jsonResponse(['error' => 'Preencha todos os campos'], 400);
}

if ($senha !== $senha2) {
    jsonResponse(['error' => 'As senhas não coincidem'], 400);
}

initConfig();

try {
    $usuario = restablecerSenhaUsuario($cpf, $email, $whatsapp, $senha);
    jsonResponse(['ok' => true, 'usuario' => $usuario, 'message' => 'Senha atualizada com sucesso']);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
