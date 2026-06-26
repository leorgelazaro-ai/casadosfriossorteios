<?php
require_once __DIR__ . '/lib/store.php';
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['status' => 'error', 'message' => 'Dados inválidos.'], 400);
}

$sorteoId = $input['sorteo_id'] ?? '';
$sorteo = getSorteoById($sorteoId);

if (!$sorteo) {
    jsonResponse(['status' => 'error', 'message' => 'Sorteio não encontrado.'], 404);
}

if ($sorteo['estado'] !== 'activo') {
    jsonResponse(['status' => 'error', 'message' => 'Este sorteio não está disponível para compra.'], 403);
}

$nome = trim($input['nome'] ?? '');
$cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
$email = trim($input['email'] ?? '');
$whatsapp = trim($input['whatsapp'] ?? '');
$modo = $input['modo'] ?? 'manual';
$cantidad = (int) ($input['cantidad'] ?? 0);
$totalCotas = (int) $sorteo['total_cotas'];
$precioCota = (float) $sorteo['precio_cota'];

if (!$nome || !$cpf || !$email || strlen($cpf) !== 11) {
    jsonResponse(['status' => 'error', 'message' => 'Dados do cliente incompletos.'], 400);
}

if ($modo === 'aleatorio') {
    if ($cantidad < 1) {
        jsonResponse(['status' => 'error', 'message' => 'Quantidade inválida.'], 400);
    }
    $nums = asignarNumerosAleatorios($sorteoId, $cantidad, $totalCotas);
    if (empty($nums)) {
        jsonResponse(['status' => 'error', 'message' => 'Não há cotas disponíveis suficientes.'], 409);
    }
    $valor = count($nums) * $precioCota;
} else {
    $nums = normalizeNumeros($input['numeros'] ?? [], $totalCotas);
    if (empty($nums)) {
        jsonResponse(['status' => 'error', 'message' => 'Selecione pelo menos 1 número.'], 400);
    }
    $ocupadas = array_intersect($nums, numerosOcupados($sorteoId));
    if (!empty($ocupadas)) {
        jsonResponse([
            'status' => 'error',
            'message' => 'Números já reservados: ' . implode(', ', array_values($ocupadas)),
        ], 409);
    }
    $valor = count($nums) * $precioCota;
}

$cliente = compact('nome', 'cpf', 'email', 'whatsapp');
$token = getMercadoPagoToken();

if (!$token) {
    $pedido = crearPedido($sorteoId, $cliente, $nums, $valor, 'processando');
    $demoCode = '00020126580014br.gov.bcb.pix0136demo-' . time()
        . '520400005303986540' . str_pad((string) (int) round($valor * 100), 6, '0', STR_PAD_LEFT)
        . '5802BR5925Casa Dos Frios6009SAO PAULO62070503***6304DEMO';

    jsonResponse([
        'status' => 'ok',
        'demo' => true,
        'message' => 'Modo demo: configure o token Mercado Pago no Admin → Configurações.',
        'pedido_id' => $pedido['id'],
        'sorteo_id' => $sorteoId,
        'numeros' => $nums,
        'point_of_interaction' => [
            'transaction_data' => ['qr_code' => $demoCode],
        ],
        'transaction_amount' => $valor,
    ]);
}

$pedido = crearPedido($sorteoId, $cliente, $nums, $valor, 'processando');

$ambienteErr = validarAmbienteMercadoPago();
if ($ambienteErr) {
    cancelarPedido($sorteoId, $pedido['id']);
    jsonResponse(['status' => 'error', 'message' => $ambienteErr], 400);
}

$partes = preg_split('/\s+/', $nome);
$expiracao = (new DateTime('+30 minutes'))->format('Y-m-d\TH:i:s.000-03:00');

$payload = [
    'transaction_amount' => round($valor, 2),
    'description' => mb_substr($sorteo['titulo'] . ' - Cotas: ' . implode(', ', $nums), 0, 200),
    'payment_method_id' => 'pix',
    'external_reference' => $pedido['id'],
    'date_of_expiration' => $expiracao,
    'payer' => [
        'email' => $email,
        'first_name' => $partes[0],
        'last_name' => count($partes) > 1 ? implode(' ', array_slice($partes, 1)) : 'Silva',
        'identification' => ['type' => 'CPF', 'number' => $cpf],
    ],
    'metadata' => [
        'sorteo_id' => $sorteoId,
        'pedido_id' => $pedido['id'],
        'whatsapp' => $whatsapp,
        'numeros' => implode(',', $nums),
    ],
];

$webhookUrl = getWebhookUrl();
if ($webhookUrl) {
    $payload['notification_url'] = $webhookUrl;
}

$result = mercadoPagoRequest(
    'POST',
    '/v1/payments',
    $payload,
    'pix_' . $pedido['id'] . '_' . time()
);

if (!$result['ok']) {
    cancelarPedido($sorteoId, $pedido['id']);
    $err = $result['data']['message'] ?? 'Erro ao gerar PIX no Mercado Pago';
    if (!empty($result['data']['cause'][0]['description'])) {
        $err = $result['data']['cause'][0]['description'];
    }
    $err = traduzirErroMercadoPago($err);
    jsonResponse([
        'status' => 'error',
        'message' => $err,
        'details' => $result['data'],
    ], $result['http_code'] ?: 500);
}

$mp = $result['data'];
actualizarPedidoMp($sorteoId, $pedido['id'], (string) ($mp['id'] ?? ''));

$mp['pedido_id'] = $pedido['id'];
$mp['sorteo_id'] = $sorteoId;
$mp['numeros'] = $nums;
$mp['real'] = true;

jsonResponse($mp);
