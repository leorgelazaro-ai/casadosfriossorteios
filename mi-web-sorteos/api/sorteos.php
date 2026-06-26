<?php
require_once __DIR__ . '/lib/store.php';
handleOptions();

initConfig();
$id = $_GET['id'] ?? null;

if ($id) {
    $sorteo = getSorteoById($id);
    if (!$sorteo) {
        jsonResponse(['error' => 'Sorteio não encontrado'], 404);
    }
    jsonResponse(enrichSorteoPublic($sorteo));
}

$sorteos = getSorteos();
$activos = [];
$finalizados = [];

foreach ($sorteos as $s) {
    $enriched = enrichSorteoPublic($s);
    if ($s['estado'] === 'finalizado') {
        $finalizados[] = $enriched;
    } elseif ($s['estado'] === 'activo') {
        $activos[] = $enriched;
    }
}

$config = initConfig();
jsonResponse([
    'site_name' => $config['site_name'],
    'activos' => $activos,
    'finalizados' => $finalizados,
]);
