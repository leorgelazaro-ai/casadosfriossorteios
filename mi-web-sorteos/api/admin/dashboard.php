<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();

$sorteos = getSorteos();
$totalPedidos = 0;
$totalPagos = 0;
$totalProcessando = 0;
$totalReceita = 0;

foreach ($sorteos as $sorteo) {
    $data = initSorteoData($sorteo['id']);
    foreach ($data['pedidos'] as $p) {
        $totalPedidos++;
        if ($p['estado'] === 'pago') {
            $totalPagos++;
            $totalReceita += (float) $p['valor'];
        } elseif ($p['estado'] === 'processando') {
            $totalProcessando++;
        }
    }
}

$enriched = array_map(function ($s) {
    $stats = getSorteoStats($s['id']);
    return array_merge($s, $stats);
}, $sorteos);

jsonResponse([
    'stats' => [
        'sorteos' => count($sorteos),
        'usuarios' => count(getUsuarios()),
        'pedidos' => $totalPedidos,
        'pagos' => $totalPagos,
        'processando' => $totalProcessando,
        'receita' => $totalReceita,
    ],
    'sorteos' => $enriched,
]);
