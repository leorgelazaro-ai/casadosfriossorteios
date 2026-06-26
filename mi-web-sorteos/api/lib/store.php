<?php

define('DATA_DIR', dirname(__DIR__, 2) . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('SORTEOS_FILE', DATA_DIR . '/sorteos.json');
define('USUARIOS_FILE', DATA_DIR . '/usuarios.json');
define('UPLOADS_DIR', dirname(__DIR__, 2) . '/uploads/sorteos');

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    if (!is_dir(DATA_DIR . '/sorteos')) {
        mkdir(DATA_DIR . '/sorteos', 0755, true);
    }
    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }
}

function readJson(string $file, $default = [])
{
    ensureDataDir();
    if (!file_exists($file)) {
        return $default;
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function writeJson(string $file, $data): void
{
    ensureDataDir();
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function initConfig(): array
{
    $config = readJson(CONFIG_FILE, null);
    if ($config === null) {
        $config = [
            'site_name' => 'Casa Dos Frios Sorteios',
            'admin_password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'mercadopago_test_token' => '',
            'mercadopago_prod_token' => '',
            'site_url' => '',
        ];
        writeJson(CONFIG_FILE, $config);
    }
    return migrateMercadoPagoConfig($config);
}

function migrateMercadoPagoConfig(array $config): array
{
    $legacy = trim($config['mercadopago_access_token'] ?? '');
    if ($legacy !== '') {
        if (stripos($legacy, 'TEST-') === 0 && empty($config['mercadopago_test_token'])) {
            $config['mercadopago_test_token'] = $legacy;
        } elseif (stripos($legacy, 'APP_USR-') === 0 && empty($config['mercadopago_prod_token'])) {
            $config['mercadopago_prod_token'] = $legacy;
        }
        unset($config['mercadopago_access_token']);
        writeJson(CONFIG_FILE, $config);
    }
    return $config;
}

function getMercadoPagoTestTokenStored(): string
{
    return trim(initConfig()['mercadopago_test_token'] ?? '');
}

function getMercadoPagoProdTokenStored(): string
{
    return trim(initConfig()['mercadopago_prod_token'] ?? '');
}

function getMercadoPagoToken(): ?string
{
    $test = getMercadoPagoTestTokenStored();
    $prod = getMercadoPagoProdTokenStored();

    if (isLocalSiteUrl()) {
        return $test !== '' ? $test : null;
    }

    if ($prod !== '') {
        return $prod;
    }
    if ($test !== '') {
        return $test;
    }

    $env = getenv('MERCADOPAGO_ACCESS_TOKEN');
    if ($env && strpos($env, 'tu-token-aqui') === false) {
        return trim($env);
    }
    return null;
}

function saveConfig(array $updates): array
{
    $config = initConfig();
    foreach ($updates as $key => $value) {
        if ($key !== 'admin_password_hash') {
            if ($key === 'site_url') {
                $config[$key] = normalizeSiteUrl((string) $value);
            } else {
                $config[$key] = $value;
            }
        }
    }
    writeJson(CONFIG_FILE, $config);
    return migrateMercadoPagoConfig($config);
}

function isMercadoPagoConfigured(): bool
{
    if (isLocalSiteUrl()) {
        return getMercadoPagoTestTokenStored() !== '';
    }
    return getMercadoPagoProdTokenStored() !== '' || getMercadoPagoTestTokenStored() !== '';
}

function isMercadoPagoTestToken(?string $token = null): bool
{
    $token = $token ?? getMercadoPagoToken();
    return $token !== null && stripos($token, 'TEST-') === 0;
}

function isMercadoPagoLiveToken(?string $token = null): bool
{
    $token = $token ?? getMercadoPagoToken();
    return $token !== null && stripos($token, 'APP_USR-') === 0;
}

function getMercadoPagoCredentialMode(?string $token = null): string
{
    if (isMercadoPagoTestToken($token)) {
        return 'test';
    }
    if (isMercadoPagoLiveToken($token)) {
        return 'production';
    }
    return 'unknown';
}

function isLocalSiteUrl(?string $url = null): bool
{
    $url = $url ?? getSiteUrl();
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $localHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'];
    if (in_array($host, $localHosts, true)) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
    return false;
}

/** Retorna mensagem de erro ou null se o ambiente é compatível com o token. */
function validarAmbienteMercadoPago(): ?string
{
    if (isLocalSiteUrl()) {
        if (getMercadoPagoTestTokenStored() === '') {
            return 'Para localhost, configure o token de TESTE (TEST-...) em Admin → Configurações. '
                . 'No Mercado Pago: Credenciais de teste → Access token. '
                . 'O token de produção (APP_USR) fica guardado para quando publicar o site.';
        }
        return null;
    }

    $token = getMercadoPagoToken();
    if (!$token) {
        return null;
    }

    if (isMercadoPagoLiveToken($token) && stripos(getSiteUrl(), 'https://') !== 0) {
        return 'Token de produção exige URL do site com HTTPS (ex: https://seusite.com.br).';
    }

    return null;
}

function traduzirErroMercadoPago(string $err): string
{
    $lower = strtolower($err);
    if (strpos($lower, 'unauthorized use of live credentials') !== false) {
        return 'Credencial de PRODUÇÃO não autorizada neste ambiente. '
            . 'Em localhost use o token TEST- (Credenciais de teste). Para PIX real com dinheiro de verdade, publique o site com HTTPS.';
    }
    return $err;
}

function getMercadoPagoEnvironmentInfo(): array
{
    $testStored = getMercadoPagoTestTokenStored();
    $prodStored = getMercadoPagoProdTokenStored();
    $local = isLocalSiteUrl();
    $active = getMercadoPagoToken();
    $mode = $active ? getMercadoPagoCredentialMode($active) : 'none';

    return [
        'credential_mode' => $mode,
        'is_localhost' => $local,
        'environment_warning' => validarAmbienteMercadoPago(),
        'test_token_saved' => $testStored !== '',
        'prod_token_saved' => $prodStored !== '',
        'test_token_preview' => $testStored !== '' ? substr($testStored, 0, 12) . '...' : '',
        'prod_token_preview' => $prodStored !== '' ? substr($prodStored, 0, 12) . '...' : '',
        'active_token_preview' => $active ? substr($active, 0, 12) . '...' : '',
    ];
}

function normalizeSiteUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }
    return rtrim($url, '/');
}

function getSiteUrl(): string
{
    $config = initConfig();
    if (!empty($config['site_url'])) {
        return normalizeSiteUrl($config['site_url']);
    }
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^(.*?)/api(?:/|$)#', $scriptDir, $m)) {
        $base = $m[1];
    } else {
        $base = $scriptDir;
    }
    if ($base === '/' || $base === '\\') {
        $base = '';
    }
    return normalizeSiteUrl($proto . '://' . $host . $base);
}

/** URL pública válida para webhook MP; null en localhost (usa polling na tela). */
function getWebhookUrl(): ?string
{
    $url = getSiteUrl() . '/api/webhook-mercadopago.php';
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $localHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'];
    if (in_array($host, $localHosts, true)) {
        return null;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $isPublic = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($isPublic === false) {
            return null;
        }
    }

    return $url;
}

function mercadoPagoRequest(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    $token = getMercadoPagoToken();
    if (!$token) {
        return ['ok' => false, 'error' => 'Token Mercado Pago não configurado', 'http_code' => 0];
    }

    $url = 'https://api.mercadopago.com' . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    if ($idempotencyKey) {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response ?: '{}', true) ?: [];

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'data' => $data,
    ];
}

function actualizarPedidoMp(string $sorteoId, string $pedidoId, string $mpPaymentId): void
{
    $file = sorteoDataFile($sorteoId);
    $data = readJson($file, ['pagas' => [], 'processando' => [], 'pedidos' => []]);
    foreach ($data['pedidos'] as &$p) {
        if ($p['id'] === $pedidoId) {
            $p['mp_payment_id'] = $mpPaymentId;
            writeJson($file, $data);
            return;
        }
    }
}

function buscarPedidoGlobal(?string $pedidoId = null, ?string $mpPaymentId = null): ?array
{
    foreach (getSorteos() as $sorteo) {
        $data = initSorteoData($sorteo['id']);
        foreach ($data['pedidos'] as $p) {
            if ($pedidoId && ($p['id'] ?? '') === $pedidoId) {
                return array_merge($p, ['sorteo_id' => $sorteo['id']]);
            }
            if ($mpPaymentId && (string) ($p['mp_payment_id'] ?? '') === (string) $mpPaymentId) {
                return array_merge($p, ['sorteo_id' => $sorteo['id']]);
            }
        }
    }
    return null;
}

function procesarPagoAprovado(array $payment): bool
{
    $status = $payment['status'] ?? '';
    if ($status !== 'approved') {
        return false;
    }

    $metadata = $payment['metadata'] ?? [];
    $sorteoId = $metadata['sorteo_id'] ?? '';
    $pedidoId = $metadata['pedido_id'] ?? ($payment['external_reference'] ?? '');

    if (!$sorteoId || !$pedidoId) {
        $found = buscarPedidoGlobal(null, (string) ($payment['id'] ?? ''));
        if ($found) {
            $sorteoId = $found['sorteo_id'];
            $pedidoId = $found['id'];
        }
    }

    if (!$sorteoId || !$pedidoId) {
        return false;
    }

    $pedido = buscarPedidoGlobal($pedidoId);
    if ($pedido && ($pedido['estado'] ?? '') === 'pago') {
        return true;
    }

    return confirmarPedido($sorteoId, $pedidoId);
}

function sorteoDataFile(string $id): string
{
    return DATA_DIR . '/sorteos/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '.json';
}

function initSorteoData(string $id): array
{
    $file = sorteoDataFile($id);
    $data = readJson($file, null);
    if ($data === null) {
        $data = ['pagas' => [], 'processando' => [], 'pedidos' => []];
        writeJson($file, $data);
    }
    return $data;
}

function getSorteos(): array
{
    $sorteos = readJson(SORTEOS_FILE, []);
    if (empty($sorteos)) {
        $sorteos = [
            [
                'id' => 'demo-ativo',
                'titulo' => 'Sorteio Smartphone 2026',
                'descricao' => 'Concorra a um smartphone top de linha. Sorteio transparente e seguro.',
                'premio' => 'Smartphone Samsung Galaxy',
                'precio_cota' => 10,
                'total_cotas' => 10000,
                'estado' => 'activo',
                'numero_ganador' => null,
                'fecha_sorteo' => '2026-08-15',
                'imagen' => '',
                'created_at' => date('c'),
            ],
            [
                'id' => 'demo-finalizado',
                'titulo' => 'Sorteio TV 55" — Encerrado',
                'descricao' => 'Sorteio realizado com sucesso. Confira o número ganhador abaixo.',
                'premio' => 'Smart TV 55 polegadas',
                'precio_cota' => 10,
                'total_cotas' => 5000,
                'estado' => 'finalizado',
                'numero_ganador' => 3847,
                'fecha_sorteo' => '2026-05-10',
                'imagen' => '',
                'created_at' => date('c', strtotime('-60 days')),
            ],
        ];
        writeJson(SORTEOS_FILE, $sorteos);
        initSorteoData('demo-ativo');
        initSorteoData('demo-finalizado');
    }
    return $sorteos;
}

function getSorteoById(string $id): ?array
{
    foreach (getSorteos() as $sorteo) {
        if ($sorteo['id'] === $id) {
            return $sorteo;
        }
    }
    return null;
}

function saveSorteos(array $sorteos): void
{
    writeJson(SORTEOS_FILE, $sorteos);
}

function validarTotalCotasSorteo(string $id, int $totalCotas): void
{
    $ocupados = numerosOcupados($id);
    if (empty($ocupados)) {
        return;
    }
    $max = max($ocupados);
    if ($totalCotas < $max) {
        throw new InvalidArgumentException(
            'Total de cotas não pode ser menor que ' . $max . ' (já vendidas ou reservadas)'
        );
    }
}

function eliminarSorteo(string $id): void
{
    $sorteo = getSorteoById($id);
    if (!$sorteo) {
        throw new RuntimeException('Sorteio não encontrado');
    }

    $data = initSorteoData($id);
    if (!empty($data['pagas'])) {
        throw new RuntimeException('Não é possível excluir: existem cotas pagas neste sorteio');
    }

    $sorteos = array_values(array_filter(getSorteos(), fn($s) => $s['id'] !== $id));
    saveSorteos($sorteos);

    $file = sorteoDataFile($id);
    if (file_exists($file)) {
        unlink($file);
    }

    if (!empty($sorteo['imagen']) && str_starts_with($sorteo['imagen'], 'uploads/')) {
        $imgPath = dirname(__DIR__, 2) . '/' . $sorteo['imagen'];
        if (file_exists($imgPath)) {
            @unlink($imgPath);
        }
    }
}

function getSorteoStats(string $id): array
{
    $sorteo = getSorteoById($id);
    if (!$sorteo) {
        return [];
    }
    $data = initSorteoData($id);
    $vendidas = count($data['pagas']);
    $total = (int) $sorteo['total_cotas'];
    return [
        'vendidas' => $vendidas,
        'processando' => count($data['processando']),
        'disponibles' => max(0, $total - $vendidas - count($data['processando'])),
        'total' => $total,
        'porcentaje' => $total > 0 ? round(($vendidas / $total) * 100, 1) : 0,
    ];
}

function enrichSorteoPublic(array $sorteo): array
{
    $stats = getSorteoStats($sorteo['id']);
    $sorteo['vendidas'] = $stats['vendidas'] ?? 0;
    $sorteo['disponibles'] = $stats['disponibles'] ?? 0;
    $sorteo['porcentaje'] = $stats['porcentaje'] ?? 0;
    return $sorteo;
}

function getCotasState(string $sorteoId): array
{
    $data = initSorteoData($sorteoId);
    return [
        'pagas' => $data['pagas'],
        'processando' => $data['processando'],
    ];
}

function normalizeNumeros(array $numeros, int $total): array
{
    $nums = array_unique(array_map('intval', $numeros));
    $nums = array_values(array_filter($nums, fn($n) => $n >= 1 && $n <= $total));
    sort($nums);
    return $nums;
}

function numerosOcupados(string $sorteoId): array
{
    $data = initSorteoData($sorteoId);
    return array_values(array_unique(array_merge($data['pagas'], $data['processando'])));
}

function asignarNumerosAleatorios(string $sorteoId, int $cantidad, int $totalCotas): array
{
    $ocupados = array_flip(numerosOcupados($sorteoId));
    $disponibles = [];
    for ($i = 1; $i <= $totalCotas; $i++) {
        if (!isset($ocupados[$i])) {
            $disponibles[] = $i;
        }
    }
    if (count($disponibles) < $cantidad) {
        return [];
    }
    shuffle($disponibles);
    return array_slice($disponibles, 0, $cantidad);
}

function crearPedido(string $sorteoId, array $cliente, array $numeros, float $valor, string $estado = 'processando'): array
{
    $file = sorteoDataFile($sorteoId);
    $data = initSorteoData($sorteoId);

    $pedido = [
        'id' => 'ped-' . uniqid(),
        'sorteo_id' => $sorteoId,
        'nome' => trim($cliente['nome'] ?? ''),
        'cpf' => preg_replace('/\D/', '', $cliente['cpf'] ?? ''),
        'email' => trim($cliente['email'] ?? ''),
        'whatsapp' => trim($cliente['whatsapp'] ?? ''),
        'numeros' => $numeros,
        'valor' => $valor,
        'estado' => $estado,
        'created_at' => date('c'),
    ];

    $data['processando'] = array_values(array_unique(array_merge($data['processando'], $numeros)));
    $data['pedidos'][] = $pedido;
    writeJson($file, $data);

    return $pedido;
}

function confirmarPedido(string $sorteoId, string $pedidoId): bool
{
    $file = sorteoDataFile($sorteoId);
    $data = readJson($file, ['pagas' => [], 'processando' => [], 'pedidos' => []]);
    $found = false;

    foreach ($data['pedidos'] as &$pedido) {
        if ($pedido['id'] === $pedidoId && $pedido['estado'] === 'processando') {
            $pedido['estado'] = 'pago';
            $pedido['paid_at'] = date('c');
            foreach ($pedido['numeros'] as $n) {
                $data['processando'] = array_values(array_diff($data['processando'], [$n]));
                if (!in_array($n, $data['pagas'])) {
                    $data['pagas'][] = $n;
                }
            }
            $data['pagas'] = array_values(array_unique($data['pagas']));
            sort($data['pagas']);
            $found = true;
            break;
        }
    }

    if ($found) {
        writeJson($file, $data);
    }
    return $found;
}

function cancelarPedido(string $sorteoId, string $pedidoId): bool
{
    $file = sorteoDataFile($sorteoId);
    $data = readJson($file, ['pagas' => [], 'processando' => [], 'pedidos' => []]);
    $found = false;

    foreach ($data['pedidos'] as &$pedido) {
        if ($pedido['id'] === $pedidoId && in_array($pedido['estado'], ['processando', 'pago'])) {
            foreach ($pedido['numeros'] as $n) {
                $data['processando'] = array_values(array_diff($data['processando'], [$n]));
                $data['pagas'] = array_values(array_diff($data['pagas'], [$n]));
            }
            $pedido['estado'] = 'cancelado';
            $pedido['cancelled_at'] = date('c');
            $found = true;
            break;
        }
    }

    if ($found) {
        writeJson($file, $data);
    }
    return $found;
}

function getAllPedidos(?string $sorteoId = null): array
{
    $pedidos = [];
    $sorteos = getSorteos();

    foreach ($sorteos as $sorteo) {
        if ($sorteoId && $sorteo['id'] !== $sorteoId) {
            continue;
        }
        $data = initSorteoData($sorteo['id']);
        foreach ($data['pedidos'] as $pedido) {
            $pedido['sorteo_titulo'] = $sorteo['titulo'];
            $pedido['sorteo_estado'] = $sorteo['estado'];
            $pedidos[] = $pedido;
        }
    }

    usort($pedidos, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $pedidos;
}

function jsonResponse($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function handleOptions(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        exit(0);
    }
}

function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function requireAdmin(): void
{
    startAdminSession();
    if (empty($_SESSION['admin_logged_in'])) {
        jsonResponse(['error' => 'Não autorizado'], 401);
    }
}

function normalizeFechaSorteo($fecha): ?string
{
    if ($fecha === null || $fecha === '' || $fecha === false) {
        return null;
    }
    $fecha = trim((string) $fecha);
    return $fecha !== '' ? $fecha : null;
}

function generateSorteoId(): string
{
    return 's-' . substr(uniqid(), -8);
}

function usuarioPublico(array $u): array
{
    return [
        'nome' => $u['nome'] ?? '',
        'cpf' => $u['cpf'] ?? '',
        'email' => $u['email'] ?? '',
        'whatsapp' => $u['whatsapp'] ?? '',
    ];
}

function validarSenha(string $senha): void
{
    if (strlen($senha) < 6) {
        throw new InvalidArgumentException('A senha deve ter no mínimo 6 caracteres');
    }
}

function normalizarWhatsapp(string $w): string
{
    return preg_replace('/\D/', '', $w);
}

function registrarUsuario(array $datos): array
{
    ensureDataDir();
    $usuarios = readJson(USUARIOS_FILE, []);
    if (!is_array($usuarios)) {
        $usuarios = [];
    }

    $cpf = preg_replace('/\D/', '', $datos['cpf'] ?? '');
    $senha = $datos['senha'] ?? '';
    validarSenha($senha);

    foreach ($usuarios as $u) {
        if (($u['cpf'] ?? '') === $cpf) {
            throw new InvalidArgumentException('Este CPF já está cadastrado. Faça login ou restableça a senha.');
        }
    }

    $usuario = [
        'id' => 'usr-' . substr(uniqid(), -8),
        'nome' => trim($datos['nome'] ?? ''),
        'cpf' => $cpf,
        'email' => trim($datos['email'] ?? ''),
        'whatsapp' => trim($datos['whatsapp'] ?? ''),
        'password_hash' => password_hash($senha, PASSWORD_DEFAULT),
        'created_at' => date('c'),
    ];
    $usuarios[] = $usuario;
    writeJson(USUARIOS_FILE, $usuarios);
    return usuarioPublico($usuario);
}

function loginCliente(string $cpf, string $senha): array
{
    $cpf = preg_replace('/\D/', '', $cpf);

    foreach (getUsuarios() as $u) {
        if (($u['cpf'] ?? '') !== $cpf) {
            continue;
        }
        if (empty($u['password_hash'])) {
            throw new InvalidArgumentException('Conta sem senha definida. Use "Restablecer senha".');
        }
        if (!password_verify($senha, $u['password_hash'])) {
            throw new InvalidArgumentException('CPF ou senha incorretos');
        }
        return usuarioPublico($u);
    }

    throw new InvalidArgumentException('CPF ou senha incorretos');
}

function restablecerSenhaUsuario(string $cpf, string $email, string $whatsapp, string $senha): array
{
    ensureDataDir();
    validarSenha($senha);

    $cpf = preg_replace('/\D/', '', $cpf);
    $email = strtolower(trim($email));
    $whatsapp = normalizarWhatsapp($whatsapp);
    $usuarios = readJson(USUARIOS_FILE, []);

    foreach ($usuarios as $i => $u) {
        $matchCpf = ($u['cpf'] ?? '') === $cpf;
        $matchEmail = strtolower($u['email'] ?? '') === $email;
        $matchWa = normalizarWhatsapp($u['whatsapp'] ?? '') === $whatsapp;

        if ($matchCpf && $matchEmail && $matchWa) {
            $usuarios[$i]['password_hash'] = password_hash($senha, PASSWORD_DEFAULT);
            $usuarios[$i]['updated_at'] = date('c');
            writeJson(USUARIOS_FILE, $usuarios);
            return usuarioPublico($usuarios[$i]);
        }
    }

    throw new InvalidArgumentException('Dados não conferem. Verifique CPF, e-mail e WhatsApp cadastrados.');
}

function getUsuarios(): array
{
    ensureDataDir();
    $usuarios = readJson(USUARIOS_FILE, []);
    if (!is_array($usuarios)) {
        return [];
    }

    usort($usuarios, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return array_values($usuarios);
}

function getUsuariosAdmin(): array
{
    return array_map(function ($u) {
        unset($u['password_hash']);
        return $u;
    }, getUsuarios());
}

function sortearSorteo(string $id, ?int $numeroManual = null): array
{
    $sorteo = getSorteoById($id);
    if (!$sorteo) {
        throw new RuntimeException('Sorteio não encontrado');
    }
    if ($sorteo['estado'] === 'finalizado') {
        throw new RuntimeException('Este sorteio já foi finalizado');
    }

    $data = initSorteoData($id);
    if (empty($data['pagas'])) {
        throw new RuntimeException('Não há cotas pagas para realizar o sorteio');
    }

    if ($numeroManual !== null && $numeroManual > 0) {
        if (!in_array($numeroManual, $data['pagas'], true)) {
            throw new RuntimeException('O número informado não está entre as cotas pagas');
        }
        $ganador = $numeroManual;
    } else {
        $ganador = $data['pagas'][array_rand($data['pagas'])];
    }

    $sorteos = getSorteos();
    foreach ($sorteos as &$s) {
        if ($s['id'] === $id) {
            $s['estado'] = 'finalizado';
            $s['numero_ganador'] = $ganador;
            $s['finalizado_at'] = date('c');
            saveSorteos($sorteos);
            return $s;
        }
    }

    throw new RuntimeException('Erro ao finalizar sorteio');
}
