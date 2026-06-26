<?php
require_once __DIR__ . '/lib/store.php';

$topic = $_GET['topic'] ?? $_GET['type'] ?? '';
$paymentId = $_GET['id'] ?? $_GET['data.id'] ?? '';

$body = file_get_contents('php://input');
if ($body) {
    $json = json_decode($body, true);
    if ($json) {
        if (!$paymentId && isset($json['data']['id'])) {
            $paymentId = $json['data']['id'];
        }
        if (!$topic && isset($json['type'])) {
            $topic = $json['type'];
        }
        if (!$paymentId && isset($json['id']) && ($json['type'] ?? '') === 'payment') {
            $paymentId = $json['id'];
        }
    }
}

if ($paymentId && ($topic === '' || $topic === 'payment')) {
    $result = mercadoPagoRequest('GET', '/v1/payments/' . urlencode((string) $paymentId));
    if ($result['ok']) {
        procesarPagoAprovado($result['data']);
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
