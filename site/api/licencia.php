<?php
// Verificación central de licencias para Cuadra, CalificaYa y Biuan.
//
// POST { accion: "verificar", app, licenseKey, deviceId }
//   → { valida, tipo: "app"|"pase"|null, vigenteHasta, dispositivosUsados, motivo }
// POST { accion: "liberar", licenseKey, deviceId }
//   → { liberado, motivo }
//
// motivo: clave_invalida, reembolsada, contracargo, disputa, pago_fallido, pase_vencido,
//         limite_dispositivos, gumroad_no_disponible, demasiados_intentos, datos_invalidos,
//         error_servidor.
declare(strict_types=1);
define('TRUCOS_API', true);
require __DIR__ . '/_licencias.php';

cors();

function rechazo(string $motivo, ?string $tipo = null, ?int $usados = null, int $codigo = 200): void
{
    responder($codigo, ['valida' => false, 'tipo' => $tipo, 'vigenteHasta' => null, 'dispositivosUsados' => $usados, 'motivo' => $motivo]);
}

$entrada = leerJsonEntrada();
$accion = (string)($entrada['accion'] ?? '');
$clave = (string)($entrada['licenseKey'] ?? '');
$dispositivo = trim((string)($entrada['deviceId'] ?? ''));

if (!claveValida($clave) || !dispositivoValido($dispositivo) || !in_array($accion, ['verificar', 'liberar'], true)) {
    rechazo('datos_invalidos', null, null, 400);
}
$hash = hashClave($clave);

try {
    limitar('licencia', (int)($CONFIG['limite_peticiones'] ?? 30), (int)($CONFIG['limite_ventana'] ?? 600));

    if ($accion === 'liberar') {
        responder(200, ['liberado' => liberarDispositivo($hash, $dispositivo), 'motivo' => null]);
    }

    $app = (string)($entrada['app'] ?? '');
    $aceptados = $CONFIG['apps'][$app] ?? null;
    if (!is_array($aceptados)) {
        rechazo('datos_invalidos', null, null, 400);
    }

    $hoy = date('Y-m-d');
    $permitirPruebas = !empty($CONFIG['permitir_compras_prueba']);
    $sinRespuesta = false;
    $rechazoEncontrado = null; // la clave existe en algún producto, pero no da acceso

    foreach ($aceptados as $productId) {
        $producto = $CONFIG['productos'][$productId] ?? null;
        if (!is_array($producto)) {
            continue;
        }
        $r = compraEnGumroad($clave, $hash, $productId);
        if ($r === null) {
            $sinRespuesta = true;
            continue;
        }
        $ev = evaluarCompra($r, $productId, $hoy, $permitirPruebas);
        if (!$ev['valida']) {
            if ($ev['motivo'] !== 'clave_invalida' && $rechazoEncontrado === null) {
                $rechazoEncontrado = [$ev['motivo'], $producto['tipo']];
            }
            continue;
        }
        [$permitido, $usados] = registrarDispositivo($hash, $app, $dispositivo, (int)$producto['max_dispositivos']);
        if (!$permitido) {
            rechazo('limite_dispositivos', $producto['tipo'], $usados);
        }
        responder(200, [
            'valida' => true,
            'tipo' => $producto['tipo'],
            'vigenteHasta' => $ev['vigenteHasta'],
            'dispositivosUsados' => $usados,
            'motivo' => null,
        ]);
    }

    // Ningún producto dio acceso. Si Gumroad no contestó para alguno, no se puede afirmar que la clave
    // no sirve: error claro (503) y sin acceso nuevo.
    if ($sinRespuesta) {
        rechazo('gumroad_no_disponible', null, null, 503);
    }
    if ($rechazoEncontrado !== null) {
        rechazo($rechazoEncontrado[0], $rechazoEncontrado[1], contarDispositivos($hash, $app));
    }
    rechazo('clave_invalida');
} catch (PDOException $e) {
    // Nunca se registra la clave: solo el error de la base de datos.
    error_log('licencia.php: ' . $e->getMessage());
    rechazo('error_servidor', null, null, 500);
}
