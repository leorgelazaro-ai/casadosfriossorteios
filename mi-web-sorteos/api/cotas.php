<?php
require_once __DIR__ . '/lib/store.php';
handleOptions();

$sorteoId = $_GET['sorteo_id'] ?? '';
if (!$sorteoId || !getSorteoById($sorteoId)) {
    jsonResponse(['error' => 'Sorteio inválido'], 400);
}

$sorteo = getSorteoById($sorteoId);
$state = getCotasState($sorteoId);

jsonResponse([
    'pagas' => $state['pagas'],
    'processando' => $state['processando'],
    'total' => (int) $sorteo['total_cotas'],
]);
