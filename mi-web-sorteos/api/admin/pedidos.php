<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sorteoId = $_GET['sorteo_id'] ?? null;
    jsonResponse(getAllPedidos($sorteoId));
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$sorteoId = $input['sorteo_id'] ?? '';
$pedidoId = $input['pedido_id'] ?? '';

if ($action === 'confirmar') {
    if (confirmarPedido($sorteoId, $pedidoId)) {
        jsonResponse(['ok' => true, 'message' => 'Pagamento confirmado']);
    }
    jsonResponse(['error' => 'Pedido não encontrado'], 404);
}

if ($action === 'cancelar') {
    if (cancelarPedido($sorteoId, $pedidoId)) {
        jsonResponse(['ok' => true, 'message' => 'Pedido cancelado']);
    }
    jsonResponse(['error' => 'Pedido não encontrado'], 404);
}

jsonResponse(['error' => 'Ação inválida'], 400);
