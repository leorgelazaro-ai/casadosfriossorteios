<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || empty($input['nome']) || empty($input['cpf']) || empty($input['email']) || empty($input['numeros']) || empty($input['valor'])) {
    echo json_encode(["status" => "error", "message" => "Dados inválidos."]);
    exit;
}

$token = getenv("MERCADOPAGO_ACCESS_TOKEN");
$valor = (float) $input['valor'];

if (!$token) {
    $demoCode = "00020126580014br.gov.bcb.pix0136demo-" . time()
        . "520400005303986540" . str_replace(".", "", (string) $valor)
        . "5802BR5925Casa Dos Frios Sorteios6009SAO PAULO62070503***6304DEMO";

    echo json_encode([
        "status" => "ok",
        "demo" => true,
        "message" => "Modo demo: configure MERCADOPAGO_ACCESS_TOKEN no servidor para pagamentos reais.",
        "point_of_interaction" => [
            "transaction_data" => ["qr_code" => $demoCode]
        ],
        "transaction_amount" => $valor
    ]);
    exit;
}

$idempotency_key = "key_" . uniqid() . rand(1000, 9999);

$nombre_completo = trim($input['nome']);
$partes_nombre = explode(" ", $nombre_completo);
$first_name = $partes_nombre[0];
$last_name = isset($partes_nombre[1]) ? implode(" ", array_slice($partes_nombre, 1)) : "Silva";

$payload = [
    "transaction_amount" => $valor,
    "description" => "Cotas Casa Dos Frios - Números: " . implode(", ", $input['numeros']),
    "payment_method_id" => "pix",
    "payer" => [
        "email" => trim($input['email']),
        "first_name" => $first_name,
        "last_name" => $last_name,
        "identification" => [
            "type" => "CPF",
            "number" => preg_replace('/[^0-9]/', '', $input['cpf'])
        ]
    ]
];

$ch = curl_init("https://api.mercadopago.com/v1/payments");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $token,
    "Content-Type: application/json",
    "X-Idempotency-Key: " . $idempotency_key
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
curl_close($ch);

echo $response;
