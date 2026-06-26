<?php
require_once __DIR__ . '/../lib/store.php';
handleOptions();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(getSorteos());
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['error' => 'Dados inválidos'], 400);
}

$action = $input['action'] ?? '';
$sorteos = getSorteos();

if ($action === 'crear') {
    $titulo = trim($input['titulo'] ?? '');
    if ($titulo === '') {
        jsonResponse(['error' => 'O título é obrigatório'], 400);
    }

    $precio = (float) ($input['precio_cota'] ?? 10);
    $total = (int) ($input['total_cotas'] ?? 10000);

    if ($precio <= 0) {
        jsonResponse(['error' => 'Preço por cota deve ser maior que zero'], 400);
    }
    if ($total < 1) {
        jsonResponse(['error' => 'Total de cotas deve ser pelo menos 1'], 400);
    }

    $nuevo = [
        'id' => generateSorteoId(),
        'titulo' => $titulo,
        'descricao' => trim($input['descricao'] ?? ''),
        'premio' => trim($input['premio'] ?? ''),
        'precio_cota' => $precio,
        'total_cotas' => $total,
        'estado' => 'activo',
        'numero_ganador' => null,
        'fecha_sorteo' => normalizeFechaSorteo($input['fecha_sorteo'] ?? null),
        'imagen' => trim($input['imagen'] ?? ''),
        'created_at' => date('c'),
    ];

    $sorteos[] = $nuevo;
    saveSorteos($sorteos);
    initSorteoData($nuevo['id']);
    jsonResponse(['ok' => true, 'sorteo' => $nuevo]);
}

if ($action === 'editar') {
    $id = $input['id'] ?? '';
    $sorteo = getSorteoById($id);
    if (!$sorteo) {
        jsonResponse(['error' => 'Sorteio não encontrado'], 404);
    }

    $titulo = trim($input['titulo'] ?? $sorteo['titulo']);
    if ($titulo === '') {
        jsonResponse(['error' => 'O título é obrigatório'], 400);
    }

    $precio = (float) ($input['precio_cota'] ?? $sorteo['precio_cota']);
    $total = (int) ($input['total_cotas'] ?? $sorteo['total_cotas']);

    if ($precio <= 0) {
        jsonResponse(['error' => 'Preço por cota deve ser maior que zero'], 400);
    }
    if ($total < 1) {
        jsonResponse(['error' => 'Total de cotas deve ser pelo menos 1'], 400);
    }

    try {
        validarTotalCotasSorteo($id, $total);
    } catch (InvalidArgumentException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    }

    foreach ($sorteos as &$s) {
        if ($s['id'] === $id) {
            $s['titulo'] = $titulo;
            $s['descricao'] = trim($input['descricao'] ?? $s['descricao']);
            $s['premio'] = trim($input['premio'] ?? $s['premio']);
            $s['precio_cota'] = $precio;
            $s['total_cotas'] = $total;
            if (array_key_exists('fecha_sorteo', $input)) {
                $s['fecha_sorteo'] = normalizeFechaSorteo($input['fecha_sorteo']);
            }
            if (isset($input['imagen'])) {
                $s['imagen'] = trim($input['imagen']);
            }
            if (isset($input['estado']) && in_array($input['estado'], ['activo', 'pausado', 'finalizado'], true)) {
                $s['estado'] = $input['estado'];
            }
            saveSorteos($sorteos);
            jsonResponse(['ok' => true, 'sorteo' => $s]);
        }
    }
    jsonResponse(['error' => 'Sorteio não encontrado'], 404);
}

if ($action === 'finalizar' || $action === 'sortear') {
    $id = $input['id'] ?? '';
    $ganador = isset($input['numero_ganador']) ? (int) $input['numero_ganador'] : null;
    try {
        $sorteo = sortearSorteo($id, $ganador && $ganador > 0 ? $ganador : null);
        jsonResponse(['ok' => true, 'sorteo' => $sorteo, 'numero_ganador' => $sorteo['numero_ganador']]);
    } catch (RuntimeException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

if ($action === 'eliminar') {
    $id = $input['id'] ?? '';
    try {
        eliminarSorteo($id);
        jsonResponse(['ok' => true, 'message' => 'Sorteio excluído']);
    } catch (RuntimeException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

jsonResponse(['error' => 'Ação inválida'], 400);
