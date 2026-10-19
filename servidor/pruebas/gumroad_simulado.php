<?php
// Gumroad simulado para las pruebas (php -S 127.0.0.1:8801 gumroad_simulado.php).
// Responde según la clave; cuenta las llamadas en $GUMROAD_DIR/llamadas para probar la caché.
// Si existe $GUMROAD_DIR/caido, responde 500 a todo (Gumroad caído).
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

$dir = getenv('GUMROAD_DIR') ?: sys_get_temp_dir();
file_put_contents($dir . '/llamadas', "x", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if (is_file($dir . '/caido')) {
    http_response_code(500);
    echo '{"success":false}';
    exit;
}

$producto = $_POST['product_id'] ?? '';
$clave = strtoupper($_POST['license_key'] ?? '');
$dias = fn(int $n) => gmdate('Y-m-d\TH:i:s\Z', time() + $n * 86400);

// clave => [producto, cambios a la compra]
$compras = [
    'CUADRA-OK-0001' => ['P_CUADRA', []],
    'CALIFICAYA-OK-01' => ['P_CALIFICAYA', []],
    'BIUAN-OK-000001' => ['P_BIUAN', []],
    'PASE-OK-0000001' => ['P_PASE', []],
    'PASE-OK-0000002' => ['P_PASE', []],
    'PASE-CANCEL-FUTURA' => ['P_PASE', ['subscription_cancelled_at' => $dias(10)]],
    'PASE-CANCEL-HOY' => ['P_PASE', ['subscription_cancelled_at' => date('c', strtotime('today 12:00'))]],
    'PASE-TERMINADO-01' => ['P_PASE', ['subscription_ended_at' => $dias(-3)]],
    'PASE-FALLIDO-001' => ['P_PASE', ['subscription_failed_at' => $dias(-1)]],
    'CUADRA-REEMBOLSO' => ['P_CUADRA', ['refunded' => true]],
    'CUADRA-CONTRACARGO' => ['P_CUADRA', ['chargebacked' => true]],
    'CUADRA-DISPUTA-01' => ['P_CUADRA', ['disputed' => true]],
];

if (str_starts_with($clave, 'LENTA')) {
    sleep(6);
}
if (str_starts_with($clave, 'CAIDA')) {
    http_response_code(502);
    echo 'Bad gateway';
    exit;
}
if (!isset($compras[$clave]) || $compras[$clave][0] !== $producto) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'That license does not exist for the provided product.']);
    exit;
}
echo json_encode([
    'success' => true,
    'uses' => 0,
    'purchase' => array_merge([
        'product_id' => $producto,
        'email' => 'comprador@ejemplo.com',
        'license_key' => $clave,
        'refunded' => false,
        'chargebacked' => false,
        'disputed' => false,
        'dispute_won' => false,
        'test' => false,
        'subscription_ended_at' => null,
        'subscription_cancelled_at' => null,
        'subscription_failed_at' => null,
    ], $compras[$clave][1]),
]);
