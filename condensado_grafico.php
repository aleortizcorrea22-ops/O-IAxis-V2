<?php
include 'backend/database.php';
include 'backend/condensado_funciones.php';

$host = 'localhost'; $db = 'lab_tritio_v2'; $user = 'root'; $pass = '';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;

$stmt = $pdo->prepare("SELECT * FROM informe_condensado WHERE anio = ? AND trimestre = ? ORDER BY STR_TO_DATE(fecha_desde, '%d/%m/%Y') ASC");
$stmt->execute([$anio, $trimestre]);
$semanas = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($semanas as &$s) {
    $stmtD = $pdo->prepare("SELECT estacion, bq_l FROM condensado_detalle WHERE informe_id = ?");
    $stmtD->execute([$s['id']]);
    $s['detalle'] = [];
    while ($d = $stmtD->fetch(PDO::FETCH_ASSOC)) { $s['detalle'][$d['estacion']] = $d['bq_l']; }
}
unset($s);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gráfico - <?= NOMBRES_TRIMESTRE[$trimestre] ?? '' ?> <?= $anio ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom"></script>
<style>
    body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
    h1 { text-align: center; font-size: 18px; text-transform: uppercase; }
    .wrap { max-width: 1400px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 20px; }
    .no-print button { background: #0369a1; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin-bottom: 10px; }
    canvas { max-height: 70vh; }
</style>
</head>
<body>
<div class="wrap">
    <div class="no-print"><button onclick="window.close()">← Cerrar</button></div>
    <h1>Concentración de Tritio en Condensado — <?= NOMBRES_TRIMESTRE[$trimestre] ?? '' ?> del <?= $anio ?></h1>
    <canvas id="chartZoom"></canvas>
</div>
<script>
const labels = <?= json_encode(array_map(fn($s)=>$s['fecha_desde'], $semanas)) ?>;
const colores = <?= json_encode(COLORES_ESTACION) ?>;
const datasets = [
    <?php foreach (ESTACIONES_CONDENSADO as $sig => $e): ?>
    { label: '<?= $e['nombre'] ?>', backgroundColor: colores['<?= $sig ?>'],
      data: <?= json_encode(array_map(function($s) use ($sig) {
          $bql = $s['detalle'][$sig] ?? null;
          return ($bql !== null && $bql !== '') ? round(bqM3($bql, $s['hum_pct'], $s['temp_c']), 3) : null;
      }, $semanas)) ?> },
    <?php endforeach; ?>
    { label: 'ALMAFUERTE', backgroundColor: colores['CA'],
      data: <?= json_encode(array_map(function($s) {
          $bql = $s['detalle']['CA'] ?? null;
          return ($bql !== null && $bql !== '') ? round(bqM3($bql, $s['almafuerte_hum_pct'], $s['almafuerte_temp_c']), 3) : null;
      }, $semanas)) ?> }
];
new Chart(document.getElementById('chartZoom'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { color: '#f8fafc' } },
            zoom: { zoom: { wheel: { enabled: true }, drag: { enabled: true }, mode: 'x' } }
        },
        scales: {
            y: { title: { display: true, text: 'Bq/m3', color: '#f8fafc' }, ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
            x: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } }
        }
    }
});
</script>
</body>
</html>
