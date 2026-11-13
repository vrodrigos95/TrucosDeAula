<?php
// Desbloqueo de la clave de respuestas del cuadernillo de repaso.
//
// POST { accion: "retos" }                 → { retos, intentosRestantes }
// POST { accion: "revisar", respuestas }   → { ok, resultados, intentosRestantes, nuevos?, motivo? }
// GET  ?accion=pdf                         → la clave en PDF, solo si la sesión ya resolvió los retos
//
// Las respuestas viven en la sesión (servidor); el navegador nunca las recibe.
// motivo: muy_rapido (menos de MIN_SEGUNDOS desde que salieron los retos),
//         sin_retos (la sesión venció), cambiaron (se acabaron los intentos y hay números nuevos).
declare(strict_types=1);
define('TRUCOS_API', true);
require __DIR__ . '/_clave.php';

const MAX_INTENTOS = 3;
const MIN_SEGUNDOS = 120;
const VIGENCIA_RETOS = 7200;
const VIGENCIA_CLAVE = 7200;
const PDF_CLAVE = __DIR__ . '/_privado/clave-repaso-algebra-geometria.pdf';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('tda_clave');
session_set_cookie_params(['lifetime' => 0, 'path' => '/api/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function json(int $codigo, array $datos): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function nuevosRetos(): array
{
    [$publico, $respuestas] = generarRetos();
    $_SESSION['clave'] = ['publico' => $publico, 'respuestas' => $respuestas, 'emitidos' => time(), 'fallos' => 0];
    return $publico;
}

function retosVigentes(): ?array
{
    $s = $_SESSION['clave'] ?? null;
    if (!is_array($s) || time() - (int)$s['emitidos'] > VIGENCIA_RETOS) {
        return null;
    }
    return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'pdf') {
    $hasta = (int)($_SESSION['desbloqueado'] ?? 0);
    if ($hasta < time() || !is_file(PDF_CLAVE)) {
        session_write_close();
        header('Location: /material/repaso-clave.html', true, 303);
        exit;
    }
    session_write_close();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="clave-cuadernillo-repaso.pdf"');
    header('Content-Length: ' . filesize(PDF_CLAVE));
    readfile(PDF_CLAVE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(405, ['ok' => false, 'motivo' => 'metodo']);
}

$entrada = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
$accion = is_array($entrada) ? (string)($entrada['accion'] ?? '') : '';

if ($accion === 'retos') {
    $s = retosVigentes();
    $publico = $s ? $s['publico'] : nuevosRetos();
    $fallos = $s ? (int)$s['fallos'] : 0;
    json(200, [
        'retos' => $publico,
        'intentosRestantes' => MAX_INTENTOS - $fallos,
        'desbloqueado' => (int)($_SESSION['desbloqueado'] ?? 0) > time()
    ]);
}

if ($accion === 'revisar') {
    $s = retosVigentes();
    if (!$s) {
        json(200, ['ok' => false, 'motivo' => 'sin_retos', 'retos' => nuevosRetos(), 'intentosRestantes' => MAX_INTENTOS]);
    }
    $espera = MIN_SEGUNDOS - (time() - (int)$s['emitidos']);
    if ($espera > 0) {
        json(200, ['ok' => false, 'motivo' => 'muy_rapido', 'espera' => $espera, 'intentosRestantes' => MAX_INTENTOS - (int)$s['fallos']]);
    }
    $dadas = is_array($entrada['respuestas'] ?? null) ? $entrada['respuestas'] : [];
    $resultados = revisarRetos($s['respuestas'], $dadas);
    if (!in_array(false, $resultados, true)) {
        $_SESSION['desbloqueado'] = time() + VIGENCIA_CLAVE;
        unset($_SESSION['clave']);
        json(200, ['ok' => true, 'resultados' => $resultados]);
    }
    $fallos = (int)$s['fallos'] + 1;
    if ($fallos >= MAX_INTENTOS) {
        json(200, ['ok' => false, 'motivo' => 'cambiaron', 'resultados' => $resultados, 'retos' => nuevosRetos(), 'intentosRestantes' => MAX_INTENTOS]);
    }
    $_SESSION['clave']['fallos'] = $fallos;
    json(200, ['ok' => false, 'resultados' => $resultados, 'intentosRestantes' => MAX_INTENTOS - $fallos]);
}

json(400, ['ok' => false, 'motivo' => 'datos_invalidos']);
