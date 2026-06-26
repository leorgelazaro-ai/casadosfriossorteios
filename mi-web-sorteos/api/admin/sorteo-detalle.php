<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();

$id = $_GET['id'] ?? '';
$sorteo = getSorteoById($id);

if (!$sorteo) {
    jsonResponse(['error' => 'Sorteio não encontrado'], 404);
}

$data = initSorteoData($id);
$stats = getSorteoStats($id);
$pedidos = getAllPedidos($id);

jsonResponse([
    'sorteo' => array_merge($sorteo, $stats),
    'pedidos' => $pedidos,
    'numeros_pagos' => $data['pagas'],
    'numeros_processando' => $data['processando'],
]);
