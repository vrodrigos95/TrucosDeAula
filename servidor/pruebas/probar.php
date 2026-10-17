<?php
// Pruebas del endpoint central de licencias contra un MySQL/MariaDB de pruebas y un Gumroad simulado.
//
//   PRUEBAS_DSN='mysql:host=127.0.0.1;dbname=trucos_pruebas;charset=utf8mb4' \
//   PRUEBAS_USUARIO=trucos PRUEBAS_CLAVE=... php servidor/pruebas/probar.php
//
// OJO: borra y vuelve a crear las tablas de esa base. Usa una base solo para pruebas.
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

$dsn = getenv('PRUEBAS_DSN') ?: 'mysql:host=127.0.0.1;dbname=trucos_pruebas;charset=utf8mb4';
$usuario = getenv('PRUEBAS_USUARIO') ?: 'trucos';
$claveDb = getenv('PRUEBAS_CLAVE') ?: 'clave-pruebas';
$raiz = dirname(__DIR__, 2);

$pdo = new PDO($dsn, $usuario, $claveDb, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['licencia_dispositivos', 'licencia_cache', 'licencia_limites'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
$pdo->exec((string)file_get_contents($raiz . '/servidor/schema.sql'));

$tmp = sys_get_temp_dir() . '/trucos-pruebas-' . getmypid();
@mkdir($tmp . '/privado', 0700, true);
@mkdir($tmp . '/gumroad', 0700, true);

function escribirConfig(string $tmp, array $extra = []): void
{
    global $dsn, $usuario, $claveDb;
    $config = array_merge([
        'db' => ['dsn' => $dsn, 'usuario' => $usuario, 'clave' => $claveDb],
        'productos' => [
            'P_CUADRA' => ['tipo' => 'app', 'max_dispositivos' => 3],
            'P_CALIFICAYA' => ['tipo' => 'app', 'max_dispositivos' => 3],
            'P_BIUAN' => ['tipo' => 'app', 'max_dispositivos' => 3],
            'P_PASE' => ['tipo' => 'pase', 'max_dispositivos' => 3],
        ],
        'apps' => [
            'cuadra' => ['P_CUADRA', 'P_PASE'],
            'calificaya' => ['P_CALIFICAYA', 'P_PASE'],
            'biuan' => ['P_BIUAN'],
        ],
        'gumroad_url' => 'http://127.0.0.1:8801/',
        'gumroad_timeout' => 3,
        'cache_horas' => 24,
        'zona_horaria' => 'America/Mexico_City',
        'limite_peticiones' => 1000,
        'limite_ventana' => 600,
        'origenes_extra' => [],
        'permitir_compras_prueba' => false,
    ], $extra);
    file_put_contents($tmp . '/privado/config.php', '<?php return ' . var_export($config, true) . ';');
}
escribirConfig($tmp);

$errores = $tmp . '/php-errores.log';
$procesos = [
    proc_open(['php', '-S', '127.0.0.1:8801', __DIR__ . '/gumroad_simulado.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1, null, ['GUMROAD_DIR' => $tmp . '/gumroad']),
    proc_open(['php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'log_errors=1', '-d', 'error_log=' . $errores, '-S', '127.0.0.1:8802', '-t', $raiz . '/site'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, null, ['TRUCOS_PRIVADO' => $tmp . '/privado']),
];
register_shutdown_function(function () use ($procesos) {
    foreach ($procesos as $p) {
        proc_terminate($p);
    }
});
usleep(500000);

function pedir(array $cuerpo, string $origen = 'https://cuadra.trucosdeaula.com', string $metodo = 'POST'): array
{
    $ch = curl_init('http://127.0.0.1:8802/api/licencia.php');
    $cabeceras = ['Content-Type: application/json'];
    if ($origen !== '') {
        $cabeceras[] = 'Origin: ' . $origen;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_POSTFIELDS => json_encode($cuerpo),
        CURLOPT_HTTPHEADER => $cabeceras,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $t = microtime(true);
    $r = (string)curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [
        'codigo' => $info['http_code'],
        'cabeceras' => substr($r, 0, $info['header_size']),
        'json' => json_decode(substr($r, $info['header_size']), true),
        'segundos' => microtime(true) - $t,
    ];
}

$verificar = fn(string $app, string $clave, string $dispositivo) =>
    pedir(['accion' => 'verificar', 'app' => $app, 'licenseKey' => $clave, 'deviceId' => $dispositivo]);
$llamadas = fn() => strlen((string)@file_get_contents($tmp . '/gumroad/llamadas'));

$fallos = 0;
function caso(string $nombre, bool $ok, $detalle = null): void
{
    global $fallos;
    echo ($ok ? "  ok   " : "  FALLA ") . $nombre . ($ok ? '' : '  → ' . json_encode($detalle, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) {
        $fallos++;
    }
}
$valida = fn(array $r, ?string $tipo = null) => $r['codigo'] === 200 && ($r['json']['valida'] ?? null) === true && ($tipo === null || $r['json']['tipo'] === $tipo);
$motivo = fn(array $r, string $m) => ($r['json']['valida'] ?? null) === false && ($r['json']['motivo'] ?? null) === $m;

echo "Casos de la especificación\n";
$r = $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a1');
caso('clave de Cuadra en Cuadra → válida (app)', $valida($r, 'app') && $r['json']['dispositivosUsados'] === 1 && $r['json']['vigenteHasta'] === null, $r['json']);
$r = $verificar('cuadra', 'PASE-OK-0000001', 'dispositivo-p1');
caso('clave del pase en Cuadra → válida (pase)', $valida($r, 'pase'), $r['json']);
$r = $verificar('calificaya', 'PASE-OK-0000001', 'dispositivo-p1');
caso('clave del pase en CalificaYa → válida (pase)', $valida($r, 'pase') && $r['json']['dispositivosUsados'] === 1, $r['json']);
$r = $verificar('biuan', 'PASE-OK-0000001', 'dispositivo-p1');
caso('clave del pase en Biuan → no', $motivo($r, 'clave_invalida'), $r['json']);
$r = $verificar('biuan', 'BIUAN-OK-000001', 'dispositivo-b1');
caso('clave de Biuan en Biuan → válida', $valida($r, 'app'), $r['json']);
$r = $verificar('cuadra', 'BIUAN-OK-000001', 'dispositivo-b1');
caso('clave de Biuan en Cuadra → no', $motivo($r, 'clave_invalida'), $r['json']);
$r = $verificar('cuadra', 'PASE-CANCEL-FUTURA', 'dispositivo-c1');
caso('pase cancelado con fecha futura → válido, con vigenteHasta', $valida($r, 'pase') && $r['json']['vigenteHasta'] === date('Y-m-d', time() + 10 * 86400), $r['json']);
$r = $verificar('cuadra', 'PASE-CANCEL-HOY', 'dispositivo-c2');
caso('pase cancelado con fecha de hoy → pase_vencido', $motivo($r, 'pase_vencido') && $r['json']['tipo'] === 'pase', $r['json']);
$r = $verificar('cuadra', 'PASE-TERMINADO-01', 'dispositivo-c3');
caso('pase terminado (fecha pasada) → pase_vencido', $motivo($r, 'pase_vencido'), $r['json']);
$r = $verificar('cuadra', 'PASE-FALLIDO-001', 'dispositivo-f1');
caso('pase con pago fallido → pago_fallido', $motivo($r, 'pago_fallido'), $r['json']);
$r = $verificar('cuadra', 'CUADRA-REEMBOLSO', 'dispositivo-r1');
caso('compra reembolsada → reembolsada', $motivo($r, 'reembolsada'), $r['json']);
$r = $verificar('cuadra', 'CUADRA-CONTRACARGO', 'dispositivo-r2');
caso('compra con contracargo → contracargo', $motivo($r, 'contracargo'), $r['json']);
$r = $verificar('cuadra', 'CUADRA-DISPUTA-01', 'dispositivo-r3');
caso('compra en disputa → disputa', $motivo($r, 'disputa'), $r['json']);
$r = $verificar('cuadra', 'NO-EXISTE-0000', 'dispositivo-x1');
caso('clave inexistente → clave_invalida', $motivo($r, 'clave_invalida'), $r['json']);

echo "Límite de dispositivos\n";
foreach (['2', '3'] as $d) {
    $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a' . $d);
}
$r = $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a1');
caso('dispositivo ya registrado → válido (no suma)', $valida($r) && $r['json']['dispositivosUsados'] === 3, $r['json']);
$r = $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a4');
caso('cuarto dispositivo → limite_dispositivos', $motivo($r, 'limite_dispositivos') && $r['json']['dispositivosUsados'] === 3, $r['json']);
$r = pedir(['accion' => 'liberar', 'licenseKey' => 'CUADRA-OK-0001', 'deviceId' => 'dispositivo-a2']);
caso('liberar un dispositivo → liberado', $r['codigo'] === 200 && $r['json']['liberado'] === true, $r['json']);
$r = $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a4');
caso('cuarto dispositivo después de liberar uno → válido', $valida($r) && $r['json']['dispositivosUsados'] === 3, $r['json']);
$r = pedir(['accion' => 'liberar', 'licenseKey' => 'CUADRA-OK-0001', 'deviceId' => 'dispositivo-a2']);
caso('liberar uno que ya no está → liberado: false', $r['codigo'] === 200 && $r['json']['liberado'] === false, $r['json']);
$r = $verificar('calificaya', 'PASE-OK-0000001', 'dispositivo-p2');
caso('el pase cuenta los dispositivos por app', $valida($r) && $r['json']['dispositivosUsados'] === 2, $r['json']);

echo "Gumroad y caché\n";
$antes = $llamadas();
$r = $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a1');
caso('segunda verificación usa la caché (no llama a Gumroad)', $valida($r) && $llamadas() === $antes, ['llamadas' => $llamadas() - $antes]);
touch($tmp . '/gumroad/caido');
$r = $verificar('cuadra', 'PASE-OK-0000002', 'dispositivo-n1');
caso('Gumroad caído y clave sin caché → 503 gumroad_no_disponible', $r['codigo'] === 503 && $motivo($r, 'gumroad_no_disponible'), $r);
$n = (int)$pdo->query("SELECT COUNT(*) FROM licencia_dispositivos WHERE dispositivo = 'dispositivo-n1'")->fetchColumn();
caso('Gumroad caído → no registra el dispositivo (sin acceso nuevo)', $n === 0, $n);
unlink($tmp . '/gumroad/caido');
$r = $verificar('cuadra', 'PASE-OK-0000002', 'dispositivo-n1');
caso('Gumroad de vuelta → la misma clave ya es válida (no se guardó el error)', $valida($r, 'pase'), $r['json']);
$r = $verificar('cuadra', 'LENTA-0000000', 'dispositivo-l1');
caso('Gumroad no contesta en 3 s → 503, sin esperar de más', $r['codigo'] === 503 && $motivo($r, 'gumroad_no_disponible') && $r['segundos'] < 8, ['codigo' => $r['codigo'], 'segundos' => round($r['segundos'], 1)]);
$r = $verificar('cuadra', 'CAIDA-0000000', 'dispositivo-l2');
caso('Gumroad responde 502 → 503 gumroad_no_disponible', $r['codigo'] === 503 && $motivo($r, 'gumroad_no_disponible'), $r['json']);

echo "Seguridad\n";
$r = pedir(['accion' => 'verificar', 'app' => 'cuadra', 'licenseKey' => 'CUADRA-OK-0001', 'deviceId' => 'dispositivo-a1'], 'https://malo.ejemplo.com');
caso('CORS: origen ajeno → 403', $r['codigo'] === 403, $r['codigo']);
$r = pedir(['accion' => 'verificar', 'app' => 'cuadra', 'licenseKey' => 'CUADRA-OK-0001', 'deviceId' => 'dispositivo-a1'], 'https://trucosdeaula.com.malo.com');
caso('CORS: dominio que solo empieza igual → 403', $r['codigo'] === 403, $r['codigo']);
$r = pedir([], 'https://calificaya.trucosdeaula.com', 'OPTIONS');
caso('CORS: preflight desde un subdominio → 204 con Allow-Origin', $r['codigo'] === 204 && str_contains($r['cabeceras'], 'Access-Control-Allow-Origin: https://calificaya.trucosdeaula.com'), $r['cabeceras']);
$r = pedir(['accion' => 'verificar', 'app' => 'otra', 'licenseKey' => 'CUADRA-OK-0001', 'deviceId' => 'dispositivo-a1']);
caso('app desconocida → 400 datos_invalidos', $r['codigo'] === 400 && $motivo($r, 'datos_invalidos'), $r['json']);
$r = pedir(['accion' => 'verificar', 'app' => 'cuadra', 'licenseKey' => "x' OR 1=1 --", 'deviceId' => 'dispositivo-a1']);
caso('clave con caracteres raros → 400 datos_invalidos', $r['codigo'] === 400, $r['json']);
$r = pedir([], 'https://cuadra.trucosdeaula.com', 'GET');
caso('GET → 405', $r['codigo'] === 405, $r['codigo']);
$r = (function () {
    $ch = curl_init('http://127.0.0.1:8802/api/_licencias.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $cuerpo = curl_exec($ch);
    return ['codigo' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'cuerpo' => $cuerpo];
})();
caso('_licencias.php directo no hace nada (404)', $r['codigo'] === 404 && $r['cuerpo'] === '', $r);

$volcado = '';
foreach (['licencia_dispositivos', 'licencia_cache', 'licencia_limites'] as $t) {
    $volcado .= json_encode($pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC));
}
$volcado .= (string)@file_get_contents($errores);
$enClaro = array_filter(['CUADRA-OK-0001', 'PASE-OK-0000001', 'BIUAN-OK-000001', 'comprador@ejemplo.com'], fn($c) => stripos($volcado, $c) !== false);
caso('la base de datos y el log no tienen claves ni correos en texto plano', !$enClaro, array_values($enClaro));

escribirConfig($tmp, ['limite_peticiones' => 3]);
$pdo->exec('DELETE FROM licencia_limites');
$codigos = [];
for ($i = 0; $i < 5; $i++) {
    $codigos[] = $verificar('cuadra', 'CUADRA-OK-0001', 'dispositivo-a1')['codigo'];
}
caso('rate limit por IP: después de 3 peticiones → 429', $codigos === [200, 200, 200, 429, 429], $codigos);

echo $fallos === 0 ? "\nTodo bien.\n" : "\n$fallos caso(s) fallaron.\n";
exit($fallos === 0 ? 0 : 1);
