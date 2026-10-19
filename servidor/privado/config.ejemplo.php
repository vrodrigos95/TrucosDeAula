<?php
// Copia este archivo como config.php dentro de trucosdeaula-privado/ (AL LADO de public_html,
// no adentro) y llena los valores. Nunca lo subas al repositorio.
return [
    // hPanel → Bases de datos → MySQL. El host en Hostinger suele ser "localhost".
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=u000000000_trucos;charset=utf8mb4',
        'usuario' => 'u000000000_trucos',
        'clave' => 'CAMBIA_ESTA_CLAVE',
    ],

    // Product ID de cada producto (Gumroad → producto → Content → License key → product_id).
    // tipo: "app" (compra única) o "pase" (membresía). max_dispositivos: por clave y por app.
    'productos' => [
        'ID_CUADRA' => ['tipo' => 'app', 'max_dispositivos' => 3],
        'ID_CALIFICAYA' => ['tipo' => 'app', 'max_dispositivos' => 3],
        'ID_BIUAN' => ['tipo' => 'app', 'max_dispositivos' => 3],
        'ID_PASE' => ['tipo' => 'pase', 'max_dispositivos' => 3],
    ],

    // Qué productos acepta cada app, en orden. Biuan queda fuera del pase.
    'apps' => [
        'cuadra' => ['ID_CUADRA', 'ID_PASE'],
        'calificaya' => ['ID_CALIFICAYA', 'ID_PASE'],
        'biuan' => ['ID_BIUAN'],
    ],

    'gumroad_url' => 'https://api.gumroad.com/v2/licenses/verify',
    'gumroad_timeout' => 3,      // segundos
    'cache_horas' => 24,
    'zona_horaria' => 'America/Mexico_City',

    // Peticiones por IP: 30 cada 10 minutos (una escuela comparte IP: no lo bajes mucho).
    'limite_peticiones' => 30,
    'limite_ventana' => 600,

    // Orígenes extra para CORS, solo para pruebas (p. ej. 'http://localhost:5173').
    // trucosdeaula.com y sus subdominios ya están permitidos.
    'origenes_extra' => [],

    // true solo para probar con compras de prueba de Gumroad.
    'permitir_compras_prueba' => false,
];
