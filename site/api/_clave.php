<?php
// Retos para desbloquear la clave del cuadernillo de repaso (los usa clave.php).
//
// Cada reto lleva números al azar y pide pasos intermedios, no solo el resultado.
// Las respuestas se quedan en la sesión del servidor; al navegador solo le llega
// lo necesario para dibujar el enunciado y los campos.
declare(strict_types=1);

if (!defined('TRUCOS_API')) {
    http_response_code(404);
    exit;
}

function azar(int $min, int $max, array $excepto = []): int
{
    do {
        $n = random_int($min, $max);
    } while (in_array($n, $excepto, true));
    return $n;
}

function elegir(array $lista)
{
    return $lista[random_int(0, count($lista) - 1)];
}

// "3x − 2y", "−x + 5": coeficiente y variable con signos tipográficos.
function termino(int $coef, string $var, bool $primero): string
{
    if ($coef === 0) {
        return '';
    }
    $abs = abs($coef);
    $num = ($abs === 1 && $var !== '') ? '' : (string)$abs;
    if ($primero) {
        return ($coef < 0 ? '−' : '') . $num . $var;
    }
    return ($coef < 0 ? ' − ' : ' + ') . $num . $var;
}

function lineal(int $a, int $b, int $c): string
{
    return termino($a, 'x', true) . termino($b, 'y', false) . ' = ' . numero($c);
}

function numero($n): string
{
    return $n < 0 ? '−' . abs($n) : (string)$n;
}

function expr(int $p, int $q): string
{
    // p·x + q  →  "3x + 10", "2x − 40", "x"
    return termino($p, 'x', true) . ($q === 0 ? '' : termino($q, '', false));
}

// --- Un reto por tema ---------------------------------------------------------

function retoDespeje(): array
{
    $tipo = random_int(0, 2);
    if ($tipo === 0) {
        do {
            $B = azar(4, 20);
            $b = azar(2, 18);
            $h = azar(2, 12);
        } while ((($B + $b) * $h) % 2 !== 0);
        $A = intdiv(($B + $b) * $h, 2);
        return [
            'enunciado' => [
                ['t' => 'Despeja b y calcula su valor.'],
                ['frac' => ['pre' => 'A = ', 'num' => '(B + b)·h', 'den' => '2', 'post' => '']],
                ['t' => "con A = $A, B = $B y h = $h."]
            ],
            'campos' => [
                ['id' => 'p1', 'etiqueta' => 'Multiplica por 2:  2A =', 'r' => 2 * $A],
                ['id' => 'p2', 'etiqueta' => 'Divide entre h:  B + b =', 'r' => $B + $b],
                ['id' => 'p3', 'etiqueta' => 'b =', 'r' => $b]
            ]
        ];
    }
    if ($tipo === 1) {
        $t = azar(2, 9);
        $a = azar(-6, 9, [0]);
        $v0 = azar(0, 30);
        $vf = $v0 + $a * $t;
        return [
            'enunciado' => [
                ['t' => 'Despeja v₀ (velocidad inicial) y calcula su valor.'],
                ['frac' => ['pre' => 'a = ', 'num' => 'vf − v₀', 'den' => 't', 'post' => '']],
                ['t' => 'con a = ' . numero($a) . ", vf = " . numero($vf) . " y t = $t."]
            ],
            'campos' => [
                ['id' => 'p1', 'etiqueta' => 'Multiplica por t:  a·t =', 'r' => $a * $t],
                ['id' => 'p2', 'etiqueta' => 'v₀ =', 'r' => $v0]
            ]
        ];
    }
    $g = elegir([10, 2, 4, 6, 8]);
    $t = azar(2, 9);
    $h = intdiv($g * $t * $t, 2);
    return [
        'enunciado' => [
            ['t' => 'Despeja h y calcula su valor.'],
            ['raiz' => ['pre' => 't = ', 'num' => '2h', 'den' => 'g']],
            ['t' => "con t = $t y g = $g."]
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Eleva al cuadrado:  t² =', 'r' => $t * $t],
            ['id' => 'p2', 'etiqueta' => 'h =', 'r' => $h]
        ]
    ];
}

function retoDesigualdad(): array
{
    $a = azar(-6, 6, [0, 1, -1]);
    $k = azar(-9, 12, [0]);
    $b = azar(-15, 15, [0]);
    $c = $a * $k + $b;
    $op = elegir(['≤', '<', '≥', '>']);
    $voltea = ['≤' => '≥', '<' => '>', '≥' => '≤', '>' => '<'];
    $final = $a < 0 ? $voltea[$op] : $op;
    $intervalo = ['≤' => 'izq_cerrado', '<' => 'izq_abierto', '≥' => 'der_cerrado', '>' => 'der_abierto'][$final];
    return [
        'enunciado' => [
            ['t' => 'Resuelve la desigualdad y elige su intervalo.'],
            ['big' => expr($a, $b) . " $op " . numero($c)]
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Pasa el término independiente:  ' . termino($a, 'x', true) . " $op", 'r' => $a * $k],
            ['id' => 'p2', 'etiqueta' => 'Valor límite de x:', 'r' => $k],
            ['id' => 'p3', 'etiqueta' => 'Intervalo solución:', 'opciones' => [
                ['izq_cerrado', '(−∞, límite]'], ['izq_abierto', '(−∞, límite)'],
                ['der_cerrado', '[límite, ∞)'], ['der_abierto', '(límite, ∞)']
            ], 'r' => $intervalo]
        ]
    ];
}

function retoSistema(): array
{
    do {
        $a1 = azar(-6, 7, [0]);
        $b1 = azar(-6, 7, [0]);
        $a2 = azar(-6, 7, [0]);
        $b2 = azar(-6, 7, [0]);
        $d = $a1 * $b2 - $b1 * $a2;
    } while ($d === 0 || abs($d) > 60);
    $x = azar(-6, 9, [0]);
    $y = azar(-6, 9, [0]);
    $c1 = $a1 * $x + $b1 * $y;
    $c2 = $a2 * $x + $b2 * $y;
    return [
        'enunciado' => [
            ['t' => 'Resuelve por Cramer (determinantes).'],
            ['sys' => [lineal($a1, $b1, $c1), lineal($a2, $b2, $c2)]]
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Δ (determinante del sistema) =', 'r' => $d],
            ['id' => 'p2', 'etiqueta' => 'Δx =', 'r' => $c1 * $b2 - $b1 * $c2],
            ['id' => 'p3', 'etiqueta' => 'Δy =', 'r' => $a1 * $c2 - $c1 * $a2],
            ['id' => 'p4', 'etiqueta' => 'x =', 'r' => $x],
            ['id' => 'p5', 'etiqueta' => 'y =', 'r' => $y]
        ]
    ];
}

function retoProblema(): array
{
    [$p1, $p2] = elegir([[5, 10], [2, 5], [10, 20], [20, 50], [50, 100], [30, 45], [15, 25], [40, 70]]);
    $x = azar(4, 40);
    $y = azar(4, 40);
    $n = $x + $y;
    $total = $p1 * $x + $p2 * $y;
    $contexto = random_int(0, 2);
    if ($contexto === 0) {
        $texto = "Se vendieron $n boletos de \$$p1 y de \$$p2. En total se juntaron \$$total. ¿Cuántos boletos se vendieron de cada precio?";
        $cosa = 'boletos';
    } elseif ($contexto === 1) {
        $texto = "Luis tiene $n monedas de \$$p1 y de \$$p2 que suman \$$total. ¿Cuántas monedas tiene de cada una?";
        $cosa = 'monedas';
    } else {
        $texto = "Una papelería vendió $n cuadernos, unos de \$$p1 y otros de \$$p2. Cobró \$$total en total. ¿Cuántos vendió de cada precio?";
        $cosa = 'cuadernos';
    }
    return [
        'enunciado' => [
            ['t' => $texto],
            ['t' => "Llama x a los $cosa de \$$p1; y, a los de \$$p2."]
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Al eliminar x te queda ' . ($p2 - $p1) . 'y =', 'r' => $total - $p1 * $n],
            ['id' => 'p2', 'etiqueta' => 'y =', 'r' => $y],
            ['id' => 'p3', 'etiqueta' => 'x =', 'r' => $x]
        ]
    ];
}

function retoCuerpo(): array
{
    $base = elegir([[3, 'triangular'], [4, 'cuadrangular'], [5, 'pentagonal'], [6, 'hexagonal'], [7, 'heptagonal'], [8, 'octagonal'], [10, 'decagonal']]);
    $n = $base[0];
    $prisma = random_int(0, 1) === 1;
    $nombre = ($prisma ? 'un prisma ' : 'una pirámide ') . $base[1];
    return [
        'enunciado' => [
            ['t' => "Imagina $nombre. Cuenta sus caras (incluye las bases), aristas y vértices."],
            ['t' => 'Comprueba con Euler: caras + vértices − aristas = 2.']
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Caras:', 'r' => $prisma ? $n + 2 : $n + 1],
            ['id' => 'p2', 'etiqueta' => 'Aristas:', 'r' => $prisma ? 3 * $n : 2 * $n],
            ['id' => 'p3', 'etiqueta' => 'Vértices:', 'r' => $prisma ? 2 * $n : $n + 1]
        ]
    ];
}

function retoAngulos(): array
{
    $tipos = [
        ['alternos internos entre dos paralelas cortadas por una transversal', 'iguales'],
        ['opuestos por el vértice', 'iguales'],
        ['correspondientes entre dos paralelas cortadas por una transversal', 'iguales'],
        ['colaterales internos entre dos paralelas cortadas por una transversal', 's180'],
        ['adyacentes que forman un ángulo llano', 's180'],
        ['complementarios', 's90']
    ];
    [$nombre, $rel] = elegir($tipos);
    do {
        $x = azar(5, 35);
        $p1 = azar(2, 6);
        $p2 = azar(1, 5, [$p1]);
        if ($rel === 'iguales') {
            $t1 = azar(25, 155);
            $t2 = $t1;
        } else {
            $suma = $rel === 's180' ? 180 : 90;
            $t1 = azar(15, $suma - 15);
            $t2 = $suma - $t1;
        }
        $q1 = $t1 - $p1 * $x;
        $q2 = $t2 - $p2 * $x;
    } while ($q1 === 0 || $q2 === 0 || ($rel === 'iguales' && $t1 === 90));
    return [
        'enunciado' => [
            ['t' => "Dos ángulos $nombre miden:"],
            ['big' => expr($p1, $q1) . '    y    ' . expr($p2, $q2)],
            ['t' => 'Encuentra x y la medida de cada ángulo (en grados).']
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Estos ángulos…', 'opciones' => [
                ['iguales', 'son iguales'], ['s90', 'suman 90°'], ['s180', 'suman 180°']
            ], 'r' => $rel],
            ['id' => 'p2', 'etiqueta' => 'x =', 'r' => $x],
            ['id' => 'p3', 'etiqueta' => 'Primer ángulo =', 'r' => $t1],
            ['id' => 'p4', 'etiqueta' => 'Segundo ángulo =', 'r' => $t2]
        ]
    ];
}

function retoTriangulo(): array
{
    do {
        $x = azar(15, 55);
        $d = azar(-15, 40, [0]);
        $k = elegir([2, 3]);
        $t1 = $x;
        $t2 = $x + $d;
        $t3 = 180 - $t1 - $t2;
        $e = $t3 - $k * $x;
    } while ($t2 <= 5 || $t3 <= 5 || $e === 0);
    $ang = [$t1, $t2, $t3];
    $mayor = max($ang);
    $porAngulos = $mayor === 90 ? 'rectangulo' : ($mayor > 90 ? 'obtusangulo' : 'acutangulo');
    $distintos = count(array_unique($ang));
    $porLados = $distintos === 1 ? 'equilatero' : ($distintos === 2 ? 'isosceles' : 'escaleno');
    return [
        'enunciado' => [
            ['t' => 'Los ángulos de un triángulo miden:'],
            ['big' => 'x,   ' . expr(1, $d) . '   y   ' . expr($k, $e)],
            ['t' => 'Encuentra x, el ángulo mayor y clasifica el triángulo.']
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Al sumar e igualar a 180° te queda ' . (2 + $k) . 'x =', 'r' => 180 - $d - $e],
            ['id' => 'p2', 'etiqueta' => 'x =', 'r' => $x],
            ['id' => 'p3', 'etiqueta' => 'Ángulo mayor =', 'r' => $mayor],
            ['id' => 'p4', 'etiqueta' => 'Por sus ángulos:', 'opciones' => [
                ['acutangulo', 'acutángulo'], ['rectangulo', 'rectángulo'], ['obtusangulo', 'obtusángulo']
            ], 'r' => $porAngulos],
            ['id' => 'p5', 'etiqueta' => 'Por sus lados:', 'opciones' => [
                ['escaleno', 'escaleno'], ['isosceles', 'isósceles'], ['equilatero', 'equilátero']
            ], 'r' => $porLados]
        ]
    ];
}

function retoPoligono(): array
{
    $n = elegir([5, 6, 8, 9, 10, 12, 15, 18, 20]);
    $ext = intdiv(360, $n);
    $suma = ($n - 2) * 180;
    $dato = random_int(0, 1) === 0
        ? "Cada ángulo externo de un polígono regular mide {$ext}°."
        : "La suma de los ángulos internos de un polígono regular es {$suma}°.";
    return [
        'enunciado' => [
            ['t' => $dato],
            ['t' => '¿Cuántos lados tiene, cuánto mide cada ángulo interno y cuántas diagonales tiene en total?']
        ],
        'campos' => [
            ['id' => 'p1', 'etiqueta' => 'Número de lados n =', 'r' => $n],
            ['id' => 'p2', 'etiqueta' => 'Cada ángulo interno =', 'r' => 180 - $ext],
            ['id' => 'p3', 'etiqueta' => 'Diagonales desde un vértice =', 'r' => $n - 3],
            ['id' => 'p4', 'etiqueta' => 'Diagonales en total =', 'r' => intdiv($n * ($n - 3), 2)]
        ]
    ];
}

const TEMAS_CLAVE = [
    ['Despeje de fórmulas', 'retoDespeje'],
    ['Desigualdades', 'retoDesigualdad'],
    ['Sistemas 2×2', 'retoSistema'],
    ['Problemas con sistemas', 'retoProblema'],
    ['Cuerpos geométricos', 'retoCuerpo'],
    ['Ángulos', 'retoAngulos'],
    ['Triángulos', 'retoTriangulo'],
    ['Polígonos', 'retoPoligono']
];

// [publico, respuestas]: lo que ve el navegador y lo que se queda en la sesión.
function generarRetos(): array
{
    $publico = [];
    $respuestas = [];
    foreach (TEMAS_CLAVE as $i => [$tema, $fn]) {
        $r = $fn();
        $campos = [];
        foreach ($r['campos'] as $c) {
            $respuestas[$i][$c['id']] = $c['r'];
            unset($c['r']);
            $campos[] = $c;
        }
        $publico[] = ['tema' => $tema, 'enunciado' => $r['enunciado'], 'campos' => $campos];
    }
    return [$publico, $respuestas];
}

function normalizar(string $v): string
{
    $v = str_replace(['−', '–', '—', ',', '°', ' ', "\u{00A0}"], ['-', '-', '-', '.', '', '', ''], trim($v));
    return mb_strtolower($v);
}

function esCorrecta($esperada, $dada): bool
{
    if (!is_string($dada) && !is_int($dada) && !is_float($dada)) {
        return false;
    }
    $dada = normalizar((string)$dada);
    if (is_string($esperada)) {
        return $dada === $esperada;
    }
    if (!preg_match('/^-?\d+(\.\d+)?$/', $dada)) {
        return false;
    }
    return abs((float)$dada - (float)$esperada) < 0.01;
}

// Devuelve una lista de booleanos, uno por reto.
function revisarRetos(array $respuestas, array $dadas): array
{
    $res = [];
    foreach ($respuestas as $i => $campos) {
        $ok = true;
        foreach ($campos as $id => $esperada) {
            if (!esCorrecta($esperada, $dadas[$i][$id] ?? null)) {
                $ok = false;
            }
        }
        $res[] = $ok;
    }
    return $res;
}
