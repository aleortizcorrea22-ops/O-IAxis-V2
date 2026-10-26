<?php
include 'backend/database.php'; // $conn (mysqli) -> lab_tritio_v2
include 'backend/condensado_funciones.php';

$host = 'localhost'; $db = 'lab_tritio_v2'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) { die("Error de conexión: " . $e->getMessage()); }

// --- AÑO SELECCIONADO (todo el informe se presenta por año) ---
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

$stmtAnios = $pdo->query("SELECT DISTINCT anio FROM informe_condensado ORDER BY anio DESC");
$aniosDisponibles = $stmtAnios->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $aniosDisponibles)) { array_unshift($aniosDisponibles, (int)date('Y')); }
if (!in_array($anio, $aniosDisponibles)) { $aniosDisponibles[] = $anio; sort($aniosDisponibles); }

// --- VISTA (solapa) SELECCIONADA ---
$vista = $_GET['vista'] ?? '1';
$vistasValidas = ['1', '2', '3', '4', 'avance', 'promedios'];
if (!in_array($vista, $vistasValidas)) $vista = '1';

// --- GUARDAR DATOS DE HUMEDAD/TEMPERATURA DE UNA SEMANA (inline, por fila) ---
// Las semanas en sí NO se crean acá: aparecen solas, automáticamente, a partir
// de lo que ya está guardado en reportes_analisis (lo que se cargó con el botón
// "Guardar Datos" de index.php). Esta pantalla solo guarda los datos propios de
// Condensado que index.php no tiene: Fecha de Medición, Humedad, Temperatura y
// Observaciones, identificando la semana por su Fecha Desde/Hasta exacta.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_metadatos'])) {
    try {
        $fDesde = $_POST['fecha_desde']; $fHasta = $_POST['fecha_hasta'];
        $mes = (int)date('n', fechaATimestamp($fDesde));
        $trimestreCalc = $mes > 0 ? (int)ceil($mes / 3) : 1;
        $anioCalc = (int)date('Y', fechaATimestamp($fDesde));

        $stmtId = $pdo->prepare("SELECT id FROM informe_condensado WHERE fecha_desde = ? AND fecha_hasta = ?");
        $stmtId->execute([$fDesde, $fHasta]);
        $existeId = $stmtId->fetchColumn();

        $valores = [
            $trimestreCalc, $anioCalc, $fDesde, $fHasta,
            '', // fecha_medicion ya no se guarda acá: se toma en vivo de reportes_analisis.fecha_archivo
            $_POST['hum_pct'] !== '' ? str_replace(',', '.', $_POST['hum_pct']) : null,
            $_POST['temp_c'] !== '' ? str_replace(',', '.', $_POST['temp_c']) : null,
            $_POST['almafuerte_fecha_desde'] ?? '', $_POST['almafuerte_fecha_hasta'] ?? '',
            $_POST['almafuerte_hum_pct'] !== '' ? str_replace(',', '.', $_POST['almafuerte_hum_pct']) : null,
            $_POST['almafuerte_temp_c'] !== '' ? str_replace(',', '.', $_POST['almafuerte_temp_c']) : null,
            $_POST['observaciones'] ?? ''
        ];

        if ($existeId) {
            $pdo->prepare("UPDATE informe_condensado SET
                trimestre=?, anio=?, fecha_desde=?, fecha_hasta=?, fecha_medicion=?, hum_pct=?, temp_c=?,
                almafuerte_fecha_desde=?, almafuerte_fecha_hasta=?, almafuerte_hum_pct=?, almafuerte_temp_c=?, observaciones=?
                WHERE id = ?")->execute(array_merge($valores, [$existeId]));
        } else {
            $pdo->prepare("INSERT INTO informe_condensado
                (trimestre, anio, fecha_desde, fecha_hasta, fecha_medicion, hum_pct, temp_c,
                 almafuerte_fecha_desde, almafuerte_fecha_hasta, almafuerte_hum_pct, almafuerte_temp_c, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($valores);
        }
        header("Location: condensado.php?anio=" . $anioCalc . "&vista=" . $trimestreCalc);
        exit;
    } catch (Exception $e) {
        echo "<script>alert('Error al guardar: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// --- CARGA DE DATOS SEGÚN LA VISTA ---
// Las semanas se arman SIEMPRE a partir de reportes_analisis (lo cargado en index.php):
// cada fecha Desde/Hasta distinta que exista ahí aparece acá, agrupada por trimestre
// según el mes de su Fecha Desde. Los Bq/L se leen en vivo (buscarBqPorFechas); los
// metadatos propios de Condensado (humedad/temp/observaciones) se completan desde
// informe_condensado si ya se guardaron para esa fecha, o quedan vacíos si no.
function obtenerSemanasDelAnio($pdo, $anio) {
    // fecha_archivo = "Fecha Reporte" que index.php extrae del archivo .rtf/.docx arrastrado
    // (o la ingresada manualmente ahí). Es la que va en "Fecha de Medición": nunca se escribe
    // a mano acá, siempre sale del reporte real.
    $stmt = $pdo->prepare("SELECT fecha_desde, fecha_hasta, almafuerte_fecha_desde, almafuerte_fecha_hasta, fecha_archivo, id
        FROM reportes_analisis
        WHERE fecha_desde IS NOT NULL AND fecha_desde <> ''
        AND YEAR(STR_TO_DATE(fecha_desde, '%d/%m/%Y')) = ?
        ORDER BY STR_TO_DATE(fecha_desde, '%d/%m/%Y') ASC, id ASC");
    $stmt->execute([$anio]);
    $filasRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si hubiera más de un reporte con el mismo período exacto, nos quedamos con el más reciente.
    $filasPorPeriodo = [];
    foreach ($filasRaw as $fr) { $filasPorPeriodo[$fr['fecha_desde'] . '|' . $fr['fecha_hasta']] = $fr; }
    $filas = array_values($filasPorPeriodo);
    usort($filas, fn($a, $b) => fechaATimestamp($a['fecha_desde']) <=> fechaATimestamp($b['fecha_desde']));

    $stmtMeta = $pdo->prepare("SELECT * FROM informe_condensado WHERE fecha_desde = ? AND fecha_hasta = ?");

    $semanas = [];
    foreach ($filas as $f) {
        $mes = (int)date('n', fechaATimestamp($f['fecha_desde']));
        $trimestre = $mes > 0 ? (int)ceil($mes / 3) : 1;

        $stmtMeta->execute([$f['fecha_desde'], $f['fecha_hasta']]);
        $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC) ?: [];

        $detalle = buscarBqPorFechas($pdo, $f['fecha_desde'], $f['fecha_hasta'], false);
        $bqAlmafuerte = buscarBqPorFechas($pdo, $f['almafuerte_fecha_desde'], $f['almafuerte_fecha_hasta'], true);
        if (isset($bqAlmafuerte['CA'])) { $detalle['CA'] = $bqAlmafuerte['CA']; }

        $semanas[] = [
            'trimestre' => $trimestre,
            'anio' => $anio,
            'fecha_desde' => $f['fecha_desde'],
            'fecha_hasta' => $f['fecha_hasta'],
            'fecha_medicion' => $f['fecha_archivo'] ?? '', // automático, del archivo cargado en index.php
            'hum_pct' => $meta['hum_pct'] ?? null,
            'temp_c' => $meta['temp_c'] ?? null,
            'almafuerte_fecha_desde' => $f['almafuerte_fecha_desde'],
            'almafuerte_fecha_hasta' => $f['almafuerte_fecha_hasta'],
            'almafuerte_hum_pct' => $meta['almafuerte_hum_pct'] ?? null,
            'almafuerte_temp_c' => $meta['almafuerte_temp_c'] ?? null,
            'observaciones' => $meta['observaciones'] ?? '',
            'detalle' => $detalle,
        ];
    }
    return $semanas;
}

function cargarSemanas($pdo, $anio, $trimestre) {
    return array_values(array_filter(obtenerSemanasDelAnio($pdo, $anio), fn($s) => $s['trimestre'] == $trimestre));
}

function promediosTrimestre($semanas) {
    $todasEstaciones = array_merge(array_keys(ESTACIONES_CONDENSADO), array_keys(ESTACION_ALMAFUERTE));
    $sumaL = array_fill_keys($todasEstaciones, 0); $cuentaL = array_fill_keys($todasEstaciones, 0);
    $sumaM3 = array_fill_keys($todasEstaciones, 0); $cuentaM3 = array_fill_keys($todasEstaciones, 0);
    foreach ($semanas as $s) {
        foreach ($todasEstaciones as $est) {
            $bql = $s['detalle'][$est] ?? null;
            if ($bql === null || $bql === '') continue;
            $esAlmafuerte = ($est === 'CA');
            $hum = $esAlmafuerte ? $s['almafuerte_hum_pct'] : $s['hum_pct'];
            $temp = $esAlmafuerte ? $s['almafuerte_temp_c'] : $s['temp_c'];
            $sumaL[$est] += (float)$bql; $cuentaL[$est]++;
            $sumaM3[$est] += bqM3($bql, $hum, $temp); $cuentaM3[$est]++;
        }
    }
    $prom = [];
    foreach ($todasEstaciones as $est) {
        $prom[$est] = [
            'bq_l'  => $cuentaL[$est]  > 0 ? $sumaL[$est]  / $cuentaL[$est]  : null,
            'bq_m3' => $cuentaM3[$est] > 0 ? $sumaM3[$est] / $cuentaM3[$est] : null,
        ];
    }
    return $prom;
}

$semanasTrimestre = [];
if (in_array($vista, ['1','2','3','4'])) {
    $semanasTrimestre = cargarSemanas($pdo, $anio, (int)$vista);
}

$promediosPorTrimestre = [];
if ($vista === 'promedios') {
    for ($t = 1; $t <= 4; $t++) {
        $s = cargarSemanas($pdo, $anio, $t);
        $promediosPorTrimestre[$t] = promediosTrimestre($s);
    }
}

$avanceSemanas = [];
if ($vista === 'avance') {
    $avanceSemanas = obtenerSemanasDelAnio($pdo, $anio);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Concentración de Tritio en Condensado</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    body { font-family: Arial, sans-serif; background: #e9edf1; margin: 0; padding: 20px; color: #000; }
    .hoja { background: #fff; max-width: 1300px; margin: 0 auto 30px auto; padding: 18px 24px; border: 1px solid #ccc; box-shadow: 0 2px 6px rgba(0,0,0,.15); }
    .no-print { }
    .barra-superior { max-width: 1300px; margin: 0 auto 10px auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .tabs { display: flex; gap: 4px; flex-wrap: wrap; }
    .tabs a { padding: 6px 14px; background: #d9e2ec; border: 1px solid #9fb3c8; border-bottom: none; text-decoration: none; color: #1a2b3c; font-size: 12px; font-weight: bold; border-radius: 4px 4px 0 0; }
    .tabs a.activa { background: #fff; border-color: #000; color: #000; }
    select, button { font-size: 13px; padding: 4px 8px; }

    .titulo-anexo { text-align: center; font: bold 14px Arial; border-bottom: 2px solid #000; padding-bottom: 4px; }
    .titulo-principal { text-align: center; font: bold 18px Arial; margin: 6px 0; }
    .subtitulo { text-align: center; font: bold 11px Arial; }
    .subtitulo-trimestre { text-align: center; font: bold 11px Arial; border: 2px solid #000; padding: 4px; margin: 10px 0; }

    table.cond { border-collapse: collapse; width: 100%; font-family: Calibri, Arial, sans-serif; }
    table.cond th, table.cond td { border: 1px solid #000; text-align: center; padding: 2px 4px; font-size: 10px; }
    table.cond thead th { font-weight: bold; }
    table.cond .borde-grueso { border: 2px solid #000; }
    table.cond .borde-doble { border-bottom: 3px double #000; }
    table.cond .fila-dato td { font-size: 8px; }
    table.cond .fila-promedio td { font-weight: bold; background: #f2f2f2; border-top: 2px solid #000; }
    table.cond input { width: 95%; border: none; text-align: center; font-size: 10px; background: transparent; }

    .leyenda { font-size: 9px; margin-top: 8px; }
    .firmas { display: flex; justify-content: space-between; margin-top: 30px; font-size: 10px; }
    .firmas span { border-top: 1px solid #000; padding-top: 2px; display: inline-block; width: 150px; text-align: center; }

    .form-nueva-semana { margin-top: 16px; border: 1px dashed #888; padding: 10px; font-size: 11px; background: #fafafa; }
    .form-nueva-semana label { display: block; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555; margin-top: 4px; }
    .form-nueva-semana input { font-size: 11px; padding: 2px 4px; }
    .form-nueva-semana .fila { display: flex; gap: 10px; flex-wrap: wrap; }
    .form-nueva-semana fieldset { border: 1px solid #ccc; margin-top: 6px; }

    .btn-grafico { display: inline-block; margin-top: 10px; background: #0369a1; color: #fff; padding: 6px 14px; text-decoration: none; font-size: 11px; font-weight: bold; border-radius: 4px; }
    .btn-borrar { background: #b91c1c; color: #fff; border: none; padding: 2px 6px; font-size: 9px; cursor: pointer; border-radius: 3px; }
    .btn-imprimir { background: #374151; color: #fff; border: none; padding: 6px 14px; font-size: 11px; font-weight: bold; border-radius: 4px; cursor: pointer; }

    @media print {
        body { background: #fff; padding: 0; }
        .no-print { display: none !important; }
        .hoja { box-shadow: none; border: none; max-width: 100%; }
        @page { size: landscape; margin: 1cm; }
    }
</style>
</head>
<body>

<div class="barra-superior no-print">
    <form method="get" style="display:flex; gap:6px; align-items:center;">
        <label>Año:</label>
        <select name="anio" onchange="this.form.submit()">
            <?php foreach ($aniosDisponibles as $a): ?>
                <option value="<?= $a ?>" <?= $a == $anio ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
    </form>
    <div class="tabs">
        <?php foreach (['1'=>'1er Trim.','2'=>'2do Trim.','3'=>'3er Trim.','4'=>'4to Trim.','avance'=>'Inf. Avance','promedios'=>'Promedios'] as $v => $label): ?>
            <a href="?anio=<?= $anio ?>&vista=<?= $v ?>" class="<?= $vista == $v ? 'activa' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir / PDF</button>
    <a href="index.php" style="background:#0369a1; color:#fff; padding:6px 14px; text-decoration:none; font-size:11px; font-weight:bold; border-radius:4px;">💧 Volver a Tritio en Agua</a>
    <a href="../indexDOS/indexDOS.php" style="background:#374151; color:#fff; padding:6px 14px; text-decoration:none; font-size:11px; font-weight:bold; border-radius:4px;" target="_blank">⬅️ VOLVER</a>
</div>

<div class="hoja">
<?php if (in_array($vista, ['1','2','3','4'])):
    $trimestre = (int)$vista;
?>
    <div class="titulo-anexo">ANEXO " I "</div>
    <table style="width:100%; border:none;"><tr>
        <td style="width:33%; font:bold 11px Arial; border:none;">C.N.E.<br>SUBGERENCIA DE SEGURIDAD Y RADIOPROTECCION</td>
        <td style="width:34%; text-align:center; border:none;"></td>
        <td style="width:33%; text-align:right; font:bold 11px Arial; border:none;">DEPTO.DOSIMETRIA Y CONTROL AMBIENTAL<br>LABORATORIO DE ANALISIS AMBIENTALES</td>
    </tr></table>
    <div class="titulo-principal">CONCENTRACION DE TRITIO EN CONDENSADO DE HUMEDAD AMBIENTE</div>
    <div class="subtitulo-trimestre"><?= NOMBRES_TRIMESTRE[$trimestre] ?> del <?= $anio ?></div>

    <table class="cond">
        <thead>
        <tr>
            <th colspan="2" rowspan="2" class="borde-grueso">Muestreo</th>
            <th rowspan="4" class="borde-grueso">Fecha de<br>Medición</th>
            <th colspan="2" class="borde-grueso">Media del periodo</th>
            <th rowspan="4" class="borde-grueso">gr agua/m3<br>aire</th>
            <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
                <th colspan="2" class="borde-grueso"><?= $e['nombre'] ?></th>
            <?php endforeach; ?>
            <th colspan="2" rowspan="2" class="borde-grueso">Muestreo<br>Almafuerte</th>
            <th colspan="2" class="borde-grueso">Media del periodo</th>
            <th rowspan="4" class="borde-grueso">gr agua/m3<br>aire</th>
            <th colspan="2" class="borde-grueso">ALMAFUERTE</th>
            <th rowspan="4" class="no-print"></th>
        </tr>
        <tr>
            <th rowspan="3" class="borde-grueso">Hum.<br>sin %</th>
            <th rowspan="3" class="borde-grueso">Temp.<br>°C</th>
            <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
                <th colspan="2" style="font-weight:normal; font-size:8px;"><?= $e['lat'] ?></th>
            <?php endforeach; ?>
            <th rowspan="3" class="borde-grueso">Hum.<br>sin %</th>
            <th rowspan="3" class="borde-grueso">Temp.<br>°C</th>
            <th colspan="2" style="font-weight:normal; font-size:8px;"><?= ESTACION_ALMAFUERTE['CA']['lat'] ?></th>
        </tr>
        <tr>
            <th rowspan="2" class="borde-grueso">Desde</th><th rowspan="2" class="borde-grueso">Hasta</th>
            <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
                <th colspan="2" style="font-weight:normal; font-size:8px;"><?= $e['lon'] ?></th>
            <?php endforeach; ?>
            <th rowspan="2" class="borde-grueso">Desde</th><th rowspan="2" class="borde-grueso">Hasta</th>
            <th colspan="2" style="font-weight:normal; font-size:8px;"><?= ESTACION_ALMAFUERTE['CA']['lon'] ?></th>
        </tr>
        <tr>
            <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
                <th>Bq/L</th><th>Bq/m3</th>
            <?php endforeach; ?>
            <th>Bq/L</th><th>Bq/m3</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($semanasTrimestre as $i => $s): $fid = 'semana_' . $i; ?>
            <tr class="fila-dato">
                <td><?= htmlspecialchars($s['fecha_desde']) ?></td>
                <td><?= htmlspecialchars($s['fecha_hasta']) ?></td>
                <td><?= htmlspecialchars($s['fecha_medicion']) ?: '<span style="color:#b45309;">s/d</span>' ?></td>
                <td><input form="<?= $fid ?>" type="text" name="hum_pct" value="<?= $s['hum_pct'] !== null ? $s['hum_pct'] : '' ?>" placeholder="0.61" style="width:45px"></td>
                <td><input form="<?= $fid ?>" type="text" name="temp_c" value="<?= $s['temp_c'] !== null ? $s['temp_c'] : '' ?>" placeholder="24" style="width:35px"></td>
                <td><?= grAguaM3($s['temp_c']) ?></td>
                <?php foreach (ESTACIONES_CONDENSADO as $sig => $e):
                    $bql = $s['detalle'][$sig] ?? null;
                    $m3 = $bql !== null && $bql !== '' ? bqM3($bql, $s['hum_pct'], $s['temp_c']) : null;
                ?>
                    <td><?= $bql !== null && $bql !== '' ? $bql : '<span style="color:#b45309;">s/d</span>' ?></td>
                    <td><?= $m3 !== null ? number_format($m3, 3) : '-' ?></td>
                <?php endforeach; ?>
                <td><?= htmlspecialchars($s['almafuerte_fecha_desde']) ?></td>
                <td><?= htmlspecialchars($s['almafuerte_fecha_hasta']) ?></td>
                <td><input form="<?= $fid ?>" type="text" name="almafuerte_hum_pct" value="<?= $s['almafuerte_hum_pct'] !== null ? $s['almafuerte_hum_pct'] : '' ?>" placeholder="0.64" style="width:45px"></td>
                <td><input form="<?= $fid ?>" type="text" name="almafuerte_temp_c" value="<?= $s['almafuerte_temp_c'] !== null ? $s['almafuerte_temp_c'] : '' ?>" placeholder="25" style="width:35px"></td>
                <td><?= grAguaM3($s['almafuerte_temp_c']) ?></td>
                <?php
                    $bqlA = $s['detalle']['CA'] ?? null;
                    $m3A = $bqlA !== null && $bqlA !== '' ? bqM3($bqlA, $s['almafuerte_hum_pct'], $s['almafuerte_temp_c']) : null;
                ?>
                <td><?= $bqlA !== null && $bqlA !== '' ? $bqlA : '<span style="color:#b45309;">s/d</span>' ?></td>
                <td><?= $m3A !== null ? number_format($m3A, 3) : '-' ?></td>
                <td class="no-print" style="padding:0;">
                    <input form="<?= $fid ?>" type="text" name="observaciones" value="<?= htmlspecialchars($s['observaciones']) ?>" placeholder="Obs." style="width:60px; font-size:8px;">
                    <button form="<?= $fid ?>" type="submit" name="guardar_metadatos" class="btn-grafico" style="padding:2px 6px; font-size:9px; margin:0;" title="Guardar Humedad/Temp/Observaciones de esta semana">💾</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($semanasTrimestre)): ?>
            <tr><td colspan="24" style="padding:16px; font-style:italic; color:#777;">
                No hay reportes guardados en index.php con fecha dentro de <?= NOMBRES_TRIMESTRE[$trimestre] ?> <?= $anio ?> todavía.
                Cargá y guardá un reporte en index.php con Fecha Desde/Hasta de este trimestre para que aparezca acá.
            </td></tr>
        <?php endif; ?>
        <?php if (!empty($semanasTrimestre)): $prom = promediosTrimestre($semanasTrimestre); ?>
            <tr class="fila-promedio">
                <td colspan="6">PROMEDIO DEL TRIMESTRE</td>
                <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
                    <td><?= $prom[$sig]['bq_l']  !== null ? number_format($prom[$sig]['bq_l'], 2)  : '-' ?></td>
                    <td><?= $prom[$sig]['bq_m3'] !== null ? number_format($prom[$sig]['bq_m3'], 3) : '-' ?></td>
                <?php endforeach; ?>
                <td colspan="5"></td>
                <td><?= $prom['CA']['bq_l']  !== null ? number_format($prom['CA']['bq_l'], 2)  : '-' ?></td>
                <td><?= $prom['CA']['bq_m3'] !== null ? number_format($prom['CA']['bq_m3'], 3) : '-' ?></td>
                <td class="no-print"></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php foreach ($semanasTrimestre as $i => $s): ?>
        <form id="semana_<?= $i ?>" method="post" class="no-print" style="display:none;">
            <input type="hidden" name="fecha_desde" value="<?= htmlspecialchars($s['fecha_desde']) ?>">
            <input type="hidden" name="fecha_hasta" value="<?= htmlspecialchars($s['fecha_hasta']) ?>">
            <input type="hidden" name="almafuerte_fecha_desde" value="<?= htmlspecialchars($s['almafuerte_fecha_desde']) ?>">
            <input type="hidden" name="almafuerte_fecha_hasta" value="<?= htmlspecialchars($s['almafuerte_fecha_hasta']) ?>">
        </form>
    <?php endforeach; ?>

    <div class="leyenda">
        OBSERVACIONES: S/D, Equipos Fuera de Servicio &nbsp;&nbsp;&nbsp; Incertidumbre asociada a las mediciones (+/-) 15% &nbsp;-&nbsp;
        Cálculo de Concentración (Bq/m3): 1×10⁻³ × Concentración (Bq/L) × g agua/m³ aire × humedad promedio sin %<br>
        Mediciones realizadas en viales de vidrio con equipo PACKARD 1900 TR (MCD 9 Bq/L)
    </div>
    <div class="firmas">
        <span>Preparado por</span><span>Revisado por</span><span>Aprobado por</span>
    </div>

    <div class="no-print" style="margin-top:14px;">
        <canvas id="chartTrimestre" height="90"></canvas>
        <a class="btn-grafico" href="condensado_grafico.php?anio=<?= $anio ?>&trimestre=<?= $trimestre ?>" target="_blank">🔍 Ver gráfico ampliado</a>
    </div>

    <p class="no-print" style="font-size:10px; color:#555; margin-top:10px;">
        💡 Las semanas de esta tabla aparecen solas, a partir de lo que guardes en <a href="index.php">index.php</a>.
        Completá Humedad/Temp/Observaciones directo en cada fila y tocá 💾 para guardarlas.
    </p>

    <script>
        const labels = <?= json_encode(array_map(fn($s)=>$s['fecha_desde'], $semanasTrimestre)) ?>;
        const colores = <?= json_encode(COLORES_ESTACION) ?>;
        const datasets = [
            <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
            { label: '<?= $e['nombre'] ?>', backgroundColor: colores['<?= $sig ?>'],
              data: <?= json_encode(array_map(function($s) use ($sig) {
                  $bql = $s['detalle'][$sig] ?? null;
                  return ($bql !== null && $bql !== '') ? round(bqM3($bql, $s['hum_pct'], $s['temp_c']), 3) : null;
              }, $semanasTrimestre)) ?> },
            <?php endforeach; ?>
            { label: 'ALMAFUERTE', backgroundColor: colores['CA'],
              data: <?= json_encode(array_map(function($s) {
                  $bql = $s['detalle']['CA'] ?? null;
                  return ($bql !== null && $bql !== '') ? round(bqM3($bql, $s['almafuerte_hum_pct'], $s['almafuerte_temp_c']), 3) : null;
              }, $semanasTrimestre)) ?> }
        ];
        new Chart(document.getElementById('chartTrimestre'), {
            type: 'bar',
            data: { labels, datasets },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 9 } } } },
                scales: { y: { title: { display: true, text: 'Bq/m3' } } } }
        });
    </script>

<?php elseif ($vista === 'avance'): ?>
    <div class="titulo-anexo">PARA INFORME DE AVANCE <?= $anio ?></div>
    <table class="cond">
        <thead><tr>
            <th>Desde</th><th>Hasta</th>
            <?php foreach (array_merge(ESTACIONES_CONDENSADO, ESTACION_ALMAFUERTE) as $sig => $e): ?>
                <th><?= $e['nombre'] ?> (Bq/m3)</th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($avanceSemanas as $s): ?>
            <tr class="fila-dato">
                <td><?= htmlspecialchars($s['fecha_desde']) ?></td>
                <td><?= htmlspecialchars($s['fecha_hasta']) ?></td>
                <?php foreach (ESTACIONES_CONDENSADO as $sig => $e):
                    $bql = $s['detalle'][$sig] ?? null;
                    $m3 = $bql !== null && $bql !== '' ? bqM3($bql, $s['hum_pct'], $s['temp_c']) : null;
                ?>
                    <td><?= $m3 !== null ? number_format($m3, 3) : '-' ?></td>
                <?php endforeach; ?>
                <?php
                    $bqlA = $s['detalle']['CA'] ?? null;
                    $m3A = $bqlA !== null && $bqlA !== '' ? bqM3($bqlA, $s['almafuerte_hum_pct'], $s['almafuerte_temp_c']) : null;
                ?>
                <td><?= $m3A !== null ? number_format($m3A, 3) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php
            $todasEstaciones = array_merge(array_keys(ESTACIONES_CONDENSADO), array_keys(ESTACION_ALMAFUERTE));
            $sumaM3 = array_fill_keys($todasEstaciones, 0); $cuentaM3 = array_fill_keys($todasEstaciones, 0);
            foreach ($avanceSemanas as $s) {
                foreach ($todasEstaciones as $est) {
                    $bql = $s['detalle'][$est] ?? null;
                    if ($bql === null || $bql === '') continue;
                    $esA = ($est === 'CA');
                    $hum = $esA ? $s['almafuerte_hum_pct'] : $s['hum_pct'];
                    $temp = $esA ? $s['almafuerte_temp_c'] : $s['temp_c'];
                    $sumaM3[$est] += bqM3($bql, $hum, $temp); $cuentaM3[$est]++;
                }
            }
        ?>
        <tr class="fila-promedio">
            <td colspan="2">PROMEDIO <?= $anio ?></td>
            <?php foreach ($todasEstaciones as $est): ?>
                <td><?= $cuentaM3[$est] > 0 ? number_format($sumaM3[$est]/$cuentaM3[$est], 3) : '-' ?></td>
            <?php endforeach; ?>
        </tr>
        </tbody>
    </table>

<?php elseif ($vista === 'promedios'): ?>
    <div class="titulo-anexo">PROMEDIO TRIMESTRAL DE TRITIO EN CONDENSADO <?= $anio ?></div>
    <table class="cond">
        <thead>
        <tr>
            <th rowspan="2">PERIODO</th>
            <?php foreach (array_merge(ESTACIONES_CONDENSADO, ESTACION_ALMAFUERTE) as $sig => $e): ?>
                <th colspan="2"><?= $e['nombre'] ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach (array_merge(ESTACIONES_CONDENSADO, ESTACION_ALMAFUERTE) as $sig => $e): ?>
                <th>Bq/L</th><th>Bq/m3</th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php
        $anualSumaL = []; $anualSumaM3 = []; $anualCuenta = [];
        foreach ([1,2,3,4] as $t):
            $p = $promediosPorTrimestre[$t];
        ?>
            <tr>
                <td><?= strtoupper(NOMBRES_TRIMESTRE[$t]) ?></td>
                <?php foreach (array_merge(array_keys(ESTACIONES_CONDENSADO), array_keys(ESTACION_ALMAFUERTE)) as $sig): ?>
                    <td><?= $p[$sig]['bq_l']  !== null ? number_format($p[$sig]['bq_l'], 2)  : '-' ?></td>
                    <td><?= $p[$sig]['bq_m3'] !== null ? number_format($p[$sig]['bq_m3'], 3) : '-' ?></td>
                    <?php if ($p[$sig]['bq_l'] !== null) {
                        $anualSumaL[$sig] = ($anualSumaL[$sig] ?? 0) + $p[$sig]['bq_l'];
                        $anualSumaM3[$sig] = ($anualSumaM3[$sig] ?? 0) + $p[$sig]['bq_m3'];
                        $anualCuenta[$sig] = ($anualCuenta[$sig] ?? 0) + 1;
                    } ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        <tr class="fila-promedio">
            <td>PROMEDIO ANUAL</td>
            <?php foreach (array_merge(array_keys(ESTACIONES_CONDENSADO), array_keys(ESTACION_ALMAFUERTE)) as $sig): ?>
                <td><?= isset($anualCuenta[$sig]) ? number_format($anualSumaL[$sig]/$anualCuenta[$sig], 2) : '-' ?></td>
                <td><?= isset($anualCuenta[$sig]) ? number_format($anualSumaM3[$sig]/$anualCuenta[$sig], 3) : '-' ?></td>
            <?php endforeach; ?>
        </tr>
        </tbody>
    </table>
<?php endif; ?>
</div>

<script>
function mascaraFecha(input) {
    let v = input.value.replace(/\D/g, '').slice(0, 8);
    if (v.length >= 5) { input.value = `${v.slice(0,2)}/${v.slice(2,4)}/${v.slice(4,8)}`; }
    else if (v.length >= 3) { input.value = `${v.slice(0,2)}/${v.slice(2,4)}`; }
    else { input.value = v; }
}

// Nota: los Bq/L no se autocompletan ni se escriben acá — condensado.php los
// lee en vivo desde reportes_analisis/muestras_detalle cada vez que se muestra
// la página (ver backend/condensado_funciones.php -> buscarBqPorFechas()).
</script>

</body>
</html>
