<?php
// Funciones del endpoint central de licencias (licencia.php). No se llama directamente.
// Mismo enfoque que la API de Biuan: configuración fuera de public_html, CORS por lista,
// límite por IP y la clave siempre como hash SHA-256. Aquí todo se guarda en MySQL.
declare(strict_types=1);

if (!defined('TRUCOS_API')) {
    http_response_code(404);
    exit;
}

// Carpeta privada, al lado de public_html (no adentro). Se puede cambiar con TRUCOS_PRIVADO.
$rutaPrivada = getenv('TRUCOS_PRIVADO') ?: dirname(__DIR__, 2) . '/trucosdeaula-privado';
$archivoConfig = $rutaPrivada . '/config.php';
if (!is_file($archivoConfig)) {
    responder(500, ['error' => 'sin_config']);
}
$CONFIG = require $archivoConfig;
date_default_timezone_set($CONFIG['zona_horaria'] ?? 'America/Mexico_City');

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// CORS: https://trucosdeaula.com y sus subdominios (más 'origenes_extra' para pruebas locales).
function origenPermitido(string $origen): bool
{
    global $CONFIG;
    return (bool)preg_match('#^https://([a-z0-9-]+\.)*trucosdeaula\.com$#', $origen)
        || in_array($origen, $CONFIG['origenes_extra'] ?? [], true);
}

function cors(): void
{
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origen !== '') {
        if (!origenPermitido($origen)) {
            responder(403, ['error' => 'origen']);
        }
        header('Access-Control-Allow-Origin: ' . $origen);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        responder(405, ['error' => 'metodo']);
    }
}

function db(): PDO
{
    global $CONFIG;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($CONFIG['db']['dsn'], $CONFIG['db']['usuario'], $CONFIG['db']['clave'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function leerJsonEntrada(): array
{
    $crudo = file_get_contents('php://input', false, null, 0, 4096);
    $datos = json_decode((string)$crudo, true);
    return is_array($datos) ? $datos : [];
}

// La clave se normaliza igual que en Biuan para que el hash coincida en todas las apps.
function hashClave(string $clave): string
{
    return hash('sha256', strtoupper(trim($clave)));
}

function claveValida(string $clave): bool
{
    return (bool)preg_match('/^[A-Za-z0-9-]{8,64}$/', trim($clave));
}

function dispositivoValido(string $d): bool
{
    return (bool)preg_match('/^[A-Za-z0-9-]{8,64}$/', $d);
}

// Límite de peticiones por IP con ventana fija. Devuelve 429 si se excede.
function limitar(string $grupo, int $maximo, int $segundos): void
{
    $ahora = time();
    $llave = hash('sha256', $grupo . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'));
    $pdo = db();
    // conteo se calcula con el `inicio` anterior: MySQL aplica las asignaciones en orden.
    $pdo->prepare(
        'INSERT INTO licencia_limites (llave, inicio, conteo) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE conteo = IF(inicio <= ?, 1, conteo + 1), inicio = IF(inicio <= ?, ?, inicio)'
    )->execute([$llave, $ahora, $ahora - $segundos, $ahora - $segundos, $ahora]);
    $st = $pdo->prepare('SELECT conteo FROM licencia_limites WHERE llave = ?');
    $st->execute([$llave]);
    if (random_int(1, 100) === 1) {
        $pdo->prepare('DELETE FROM licencia_limites WHERE inicio < ?')->execute([$ahora - 86400]);
    }
    if ((int)$st->fetchColumn() > $maximo) {
        header('Retry-After: ' . $segundos);
        responder(429, ['valida' => false, 'tipo' => null, 'vigenteHasta' => null, 'dispositivosUsados' => null, 'motivo' => 'demasiados_intentos']);
    }
}

// --- Gumroad -------------------------------------------------------------------

// Lo único que se guarda de la respuesta de Gumroad (sin clave ni datos del comprador).
function resumirGumroad(array $g): array
{
    if (empty($g['success']) || !isset($g['purchase']) || !is_array($g['purchase'])) {
        return ['existe' => false];
    }
    $p = $g['purchase'];
    return [
        'existe' => true,
        'product_id' => isset($p['product_id']) ? (string)$p['product_id'] : null,
        'refunded' => !empty($p['refunded']),
        'chargebacked' => !empty($p['chargebacked']),
        'disputed' => !empty($p['disputed']) && empty($p['dispute_won']),
        'test' => !empty($p['test']),
        'subscription_failed_at' => $p['subscription_failed_at'] ?? null,
        'subscription_ended_at' => $p['subscription_ended_at'] ?? null,
        'subscription_cancelled_at' => $p['subscription_cancelled_at'] ?? null,
    ];
}

// Consulta Gumroad (sin sumar usos). Devuelve el resumen, o null si Gumroad no respondió.
function consultarGumroad(string $clave, string $productId): ?array
{
    global $CONFIG;
    $ch = curl_init($CONFIG['gumroad_url'] ?? 'https://api.gumroad.com/v2/licenses/verify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'product_id' => $productId,
            'license_key' => trim($clave),
            'increment_uses_count' => 'false',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($CONFIG['gumroad_timeout'] ?? 3),
        CURLOPT_CONNECTTIMEOUT => (int)($CONFIG['gumroad_timeout'] ?? 3),
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($cuerpo === false || $codigo === 0 || $codigo === 429 || $codigo >= 500) {
        return null;
    }
    $json = json_decode((string)$cuerpo, true);
    if (!is_array($json)) {
        return null;
    }
    return resumirGumroad($json);
}

// Resultado de Gumroad con caché de 24 h por (hash de la clave, producto).
// Solo se guardan respuestas definitivas; si Gumroad no respondió no se guarda nada.
function compraEnGumroad(string $clave, string $hash, string $productId): ?array
{
    global $CONFIG;
    $pdo = db();
    $limite = date('Y-m-d H:i:s', time() - 3600 * (int)($CONFIG['cache_horas'] ?? 24));
    $st = $pdo->prepare('SELECT resultado FROM licencia_cache WHERE clave_hash = ? AND product_id = ? AND consultado > ?');
    $st->execute([$hash, $productId, $limite]);
    $guardado = $st->fetchColumn();
    if ($guardado !== false) {
        $r = json_decode((string)$guardado, true);
        if (is_array($r)) {
            return $r;
        }
    }
    $r = consultarGumroad($clave, $productId);
    if ($r !== null) {
        $pdo->prepare(
            'INSERT INTO licencia_cache (clave_hash, product_id, resultado, consultado) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE resultado = VALUES(resultado), consultado = VALUES(consultado)'
        )->execute([$hash, $productId, json_encode($r), date('Y-m-d H:i:s')]);
    }
    return $r;
}

// Fecha de Gumroad (ISO 8601) como 'Y-m-d' en la zona horaria configurada.
function dia(?string $fecha): ?string
{
    if ($fecha === null || $fecha === '') {
        return null;
    }
    $t = strtotime($fecha);
    return $t === false ? null : date('Y-m-d', $t);
}

/**
 * Decide si una compra da acceso hoy.
 * Devuelve ['valida' => true, 'vigenteHasta' => ?string] o ['valida' => false, 'motivo' => string].
 * Una cancelación con fecha futura sigue valiendo hasta esa fecha: Gumroad pone ahí el fin
 * del periodo pagado, no el día en que se canceló.
 */
function evaluarCompra(array $r, string $productId, string $hoy, bool $permitirPruebas): array
{
    if (empty($r['existe']) || ($r['product_id'] !== null && $r['product_id'] !== $productId)) {
        return ['valida' => false, 'motivo' => 'clave_invalida'];
    }
    if ($r['test'] && !$permitirPruebas) {
        return ['valida' => false, 'motivo' => 'clave_invalida'];
    }
    if ($r['refunded']) {
        return ['valida' => false, 'motivo' => 'reembolsada'];
    }
    if ($r['chargebacked']) {
        return ['valida' => false, 'motivo' => 'contracargo'];
    }
    if ($r['disputed']) {
        return ['valida' => false, 'motivo' => 'disputa'];
    }
    if (!empty($r['subscription_failed_at'])) {
        return ['valida' => false, 'motivo' => 'pago_fallido'];
    }
    $fin = null;
    foreach (['subscription_ended_at', 'subscription_cancelled_at'] as $campo) {
        $d = dia($r[$campo] ?? null);
        if ($d === null) {
            continue;
        }
        if ($d <= $hoy) {
            return ['valida' => false, 'motivo' => 'pase_vencido'];
        }
        $fin = $fin === null ? $d : min($fin, $d);
    }
    return ['valida' => true, 'vigenteHasta' => $fin];
}

// --- Dispositivos ----------------------------------------------------------------

function contarDispositivos(string $hash, string $app): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM licencia_dispositivos WHERE clave_hash = ? AND app = ?');
    $st->execute([$hash, $app]);
    return (int)$st->fetchColumn();
}

/**
 * Registra el dispositivo si cabe. Devuelve [permitido, dispositivosUsados].
 * Si ya estaba registrado solo actualiza su último uso.
 */
function registrarDispositivo(string $hash, string $app, string $dispositivo, int $maximo): array
{
    $pdo = db();
    $ahora = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        // FOR UPDATE bloquea las filas (y el hueco) de esta clave: dos altas a la vez no rebasan el límite.
        $st = $pdo->prepare('SELECT dispositivo FROM licencia_dispositivos WHERE clave_hash = ? AND app = ? FOR UPDATE');
        $st->execute([$hash, $app]);
        $registrados = $st->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($dispositivo, $registrados, true)) {
            $pdo->prepare('UPDATE licencia_dispositivos SET ultimo_uso = ? WHERE clave_hash = ? AND app = ? AND dispositivo = ?')
                ->execute([$ahora, $hash, $app, $dispositivo]);
            $pdo->commit();
            return [true, count($registrados)];
        }
        if (count($registrados) >= $maximo) {
            $pdo->commit();
            return [false, count($registrados)];
        }
        $pdo->prepare('INSERT INTO licencia_dispositivos (clave_hash, app, dispositivo, alta, ultimo_uso) VALUES (?, ?, ?, ?, ?)')
            ->execute([$hash, $app, $dispositivo, $ahora, $ahora]);
        $pdo->commit();
        return [true, count($registrados) + 1];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function liberarDispositivo(string $hash, string $dispositivo): bool
{
    $st = db()->prepare('DELETE FROM licencia_dispositivos WHERE clave_hash = ? AND dispositivo = ?');
    $st->execute([$hash, $dispositivo]);
    return $st->rowCount() > 0;
}
