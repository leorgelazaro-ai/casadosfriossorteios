<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = initConfig();
    $envInfo = getMercadoPagoEnvironmentInfo();
    jsonResponse(array_merge([
        'site_name' => $config['site_name'] ?? '',
        'site_url' => $config['site_url'] ?? '',
        'mercadopago_configured' => isMercadoPagoConfigured(),
        'webhook_url' => getWebhookUrl(),
        'webhook_localhost' => getWebhookUrl() === null && isLocalSiteUrl(),
        'detected_site_url' => getSiteUrl(),
    ], $envInfo));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['error' => 'Dados inválidos'], 400);
}

$updates = [];
if (isset($input['site_name'])) {
    $updates['site_name'] = trim($input['site_name']);
}
if (isset($input['site_url'])) {
    $updates['site_url'] = rtrim(trim($input['site_url']), '/');
}

foreach (['mercadopago_test_token', 'mercadopago_prod_token'] as $field) {
    if (!isset($input[$field])) {
        continue;
    }
    $token = trim($input[$field]);
    if ($token === '' || $token === '********') {
        continue;
    }
    if ($field === 'mercadopago_test_token' && stripos($token, 'TEST-') !== 0) {
        if (stripos($token, 'TESTUSER') === 0) {
            jsonResponse([
                'error' => 'Isso é um USUÁRIO de teste (TESTUSER...), não o Access Token. '
                    . 'No painel Mercado Pago: sua aplicação → Credenciais de teste → copie o campo "Access token" (começa com TEST- e é bem longo).',
            ], 400);
        }
        jsonResponse([
            'error' => 'Token de teste inválido. Cole o Access Token de "Credenciais de teste" — começa com TEST- (ex: TEST-1234567890-041234-abc...).',
        ], 400);
    }
    if ($field === 'mercadopago_prod_token' && stripos($token, 'APP_USR-') !== 0) {
        jsonResponse([
            'error' => 'Token de produção inválido. Cole o Access Token de "Credenciais de produção" — começa com APP_USR-.',
        ], 400);
    }
    $updates[$field] = $token;
}

if (!empty($input['limpar_test_token'])) {
    $updates['mercadopago_test_token'] = '';
}
if (!empty($input['limpar_prod_token'])) {
    $updates['mercadopago_prod_token'] = '';
}

$config = saveConfig($updates);
$envInfo = getMercadoPagoEnvironmentInfo();

jsonResponse(array_merge([
    'ok' => true,
    'mercadopago_configured' => isMercadoPagoConfigured(),
    'webhook_url' => getWebhookUrl(),
    'message' => isLocalSiteUrl()
        ? ($envInfo['test_token_saved']
            ? 'Token de TESTE salvo — use localhost para simular PIX'
            : 'Cole o token TEST- no campo de teste para usar PIX no localhost')
        : ($envInfo['prod_token_saved']
            ? 'Token de PRODUÇÃO salvo — PIX real ativo'
            : 'Configure o token APP_USR- para cobrar de verdade'),
], $envInfo));
