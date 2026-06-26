<?php
require_once __DIR__ . '/lib/store.php';
handleOptions();

$sorteoId = $_GET['sorteo_id'] ?? '';
$pedidoId = $_GET['pedido_id'] ?? '';

if (!$sorteoId || !$pedidoId) {
    jsonResponse(['error' => 'Parâmetros inválidos'], 400);
}

$pedido = buscarPedidoGlobal($pedidoId);
if (!$pedido || ($pedido['sorteo_id'] ?? '') !== $sorteoId) {
    jsonResponse(['error' => 'Pedido não encontrado'], 404);
}

if (($pedido['estado'] ?? '') === 'pago') {
    jsonResponse(['ok' => true, 'status' => 'approved', 'estado' => 'pago']);
}

$mpId = $pedido['mp_payment_id'] ?? '';
if (!$mpId) {
    jsonResponse(['ok' => true, 'status' => 'pending', 'estado' => $pedido['estado'] ?? 'processando']);
}

$result = mercadoPagoRequest('GET', '/v1/payments/' . urlencode($mpId));
if (!$result['ok']) {
    jsonResponse(['ok' => true, 'status' => 'pending', 'estado' => 'processando']);
}

$payment = $result['data'];
$mpStatus = $payment['status'] ?? 'pending';

if ($mpStatus === 'approved') {
    procesarPagoAprovado($payment);
    jsonResponse(['ok' => true, 'status' => 'approved', 'estado' => 'pago']);
}

if (in_array($mpStatus, ['cancelled', 'rejected'], true)) {
    jsonResponse(['ok' => true, 'status' => $mpStatus, 'estado' => 'processando']);
}

jsonResponse(['ok' => true, 'status' => $mpStatus, 'estado' => 'processando']);
