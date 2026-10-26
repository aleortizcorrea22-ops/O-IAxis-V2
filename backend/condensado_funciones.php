<?php
// Funciones y datos compartidos por condensado.php y condensado_grafico.php

// Tabla de humedad absoluta de saturación (g agua/m3 aire) según temperatura (°C),
// tal cual la tabla de referencia del Excel original (0 a 30 °C).
$TABLA_HUMEDAD_ABS = [
    0 => 4,    1 => 4.2,  2 => 4.5,  3 => 4.9,  4 => 5.2,  5 => 5.8,
    6 => 6,    7 => 6.4,  8 => 6.8,  9 => 7.3,  10 => 7.8, 11 => 8.4,
    12 => 9,   13 => 9.6, 14 => 10.3,15 => 11,  16 => 11.7,17 => 12.4,
    18 => 13.1,19 => 14,  20 => 14.9,21 => 15.8,22 => 16.8,23 => 17.8,
    24 => 18.8,25 => 19.9,26 => 21.1,27 => 22.3,28 => 23.7,29 => 25.2,
    30 => 27
];

function grAguaM3($temp_c) {
    global $TABLA_HUMEDAD_ABS;
    if ($temp_c === null || $temp_c === '') return 0;
    $t = (int)round((float)$temp_c);
    $t = max(0, min(30, $t));
    return $TABLA_HUMEDAD_ABS[$t];
}

// Bq/m3 = 0.001 * Bq/L * Humedad(fracción) * gr agua/m3 aire  -- fórmula exacta del Excel
function bqM3($bq_l, $hum_pct, $temp_c) {
    if ($bq_l === null || $bq_l === '') return null;
    return 0.001 * (float)$bq_l * (float)$hum_pct * grAguaM3($temp_c);
}

// Estaciones del informe principal (orden igual al Excel) + Almafuerte aparte
const ESTACIONES_CONDENSADO = [
    'CTM'  => ['nombre' => 'TORRE MET.',  'lat' => 'S 32º 13´ 34"', 'lon' => 'O  64º 26´ 35"'],
    'CL'   => ['nombre' => 'LABORATORIO', 'lat' => 'S 32º 14´ 07"', 'lon' => 'O  64º 26´ 38"'],
    'CCR3' => ['nombre' => 'C. RIO III',  'lat' => 'S 32º 13´ 20"', 'lon' => 'O  64º 26´ 04"'],
    'CFA'  => ['nombre' => 'F. AEREA',    'lat' => 'S 32º 15´ 03"', 'lon' => 'O  64º 28´ 50"'],
    'CVDD' => ['nombre' => 'V. DEL DIQUE','lat' => 'S 32º 10´ 32"', 'lon' => 'O  64º 26´ 57"'],
];
const ESTACION_ALMAFUERTE = [
    'CA' => ['nombre' => 'ALMAFUERTE', 'lat' => 'S 32° 10´ 58"', 'lon' => 'O 64° 16´ 28"'],
];

const NOMBRES_TRIMESTRE = [
    1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'
];

// Colores del gráfico original (tema Office: accent1..accent6 aproximados + amarillo explícito de Almafuerte)
const COLORES_ESTACION = [
    'CTM'  => '#5B9BD5', // accent5 (azul)
    'CL'   => '#ED7D31', // accent2 (naranja)
    'CCR3' => '#A5A5A5', // accent3 (gris)
    'CFA'  => '#FFC000', // accent4 (amarillo/dorado)
    'CVDD' => '#4472C4', // accent1 (azul oscuro)
    'CA'   => '#FFFF00', // amarillo puro, igual al Excel
];

// Busca, EN VIVO, los Bq/L guardados desde index.php (reportes_analisis + muestras_detalle)
// para el período exacto Desde/Hasta indicado. $soloAlmafuerte=true compara contra las
// columnas almafuerte_fecha_desde/hasta y devuelve solo el valor de 'CA'.
// Nunca se guarda una copia de estos valores: condensado.php siempre lee la fuente real.
function buscarBqPorFechas($pdo, $desde, $hasta, $soloAlmafuerte = false) {
    if (!$desde || !$hasta) return [];
    $colDesde = $soloAlmafuerte ? 'almafuerte_fecha_desde' : 'fecha_desde';
    $colHasta = $soloAlmafuerte ? 'almafuerte_fecha_hasta' : 'fecha_hasta';

    $stmt = $pdo->prepare("SELECT id FROM reportes_analisis WHERE $colDesde = ? AND $colHasta = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$desde, $hasta]);
    $reporteId = $stmt->fetchColumn();
    if (!$reporteId) return [];

    if ($soloAlmafuerte) {
        $stmtM = $pdo->prepare("SELECT id_muestra, neto FROM muestras_detalle WHERE reporte_id = ? AND UPPER(id_muestra) = 'CA'");
    } else {
        $stmtM = $pdo->prepare("SELECT id_muestra, neto FROM muestras_detalle WHERE reporte_id = ? AND UPPER(id_muestra) != 'CA'");
    }
    $stmtM->execute([$reporteId]);
    $resultado = [];
    while ($m = $stmtM->fetch(PDO::FETCH_ASSOC)) {
        $resultado[strtoupper($m['id_muestra'])] = $m['neto'];
    }
    return $resultado;
}

function fechaATimestamp($ddmmaaaa) {
    if (!$ddmmaaaa) return 0;
    $p = explode('/', $ddmmaaaa);
    if (count($p) !== 3) return 0;
    return mktime(0, 0, 0, (int)$p[1], (int)$p[0], (int)$p[2]);
}
?>
