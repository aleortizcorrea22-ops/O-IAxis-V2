<?php
// Endpoint AJAX: busca en reportes_analisis (cargados desde index.php) un reporte
// cuyo período de muestreo coincida con el desde/hasta pedido, y devuelve el
// Bq/L (campo "neto") de cada estación encontrada en muestras_detalle.
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost'; $db = 'lab_tritio_v2'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$esAlmafuerte = isset($_GET['almafuerte']) && $_GET['almafuerte'] === '1';

if ($desde === '' || $hasta === '') {
    echo json_encode([]);
    exit;
}

// Si es el bloque Almafuerte, el período a comparar es almafuerte_fecha_desde/hasta;
// si es el bloque principal, comparamos contra fecha_desde/fecha_hasta.
$colDesde = $esAlmafuerte ? 'almafuerte_fecha_desde' : 'fecha_desde';
$colHasta = $esAlmafuerte ? 'almafuerte_fecha_hasta' : 'fecha_hasta';

$stmt = $pdo->prepare("SELECT id FROM reportes_analisis WHERE $colDesde = ? AND $colHasta = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$desde, $hasta]);
$reporteId = $stmt->fetchColumn();

$resultado = [];
if ($reporteId) {
    if ($esAlmafuerte) {
        // Solo nos interesa la muestra de Almafuerte (CA) de ese reporte
        $stmtM = $pdo->prepare("SELECT id_muestra, neto FROM muestras_detalle WHERE reporte_id = ? AND UPPER(id_muestra) = 'CA'");
        $stmtM->execute([$reporteId]);
    } else {
        $stmtM = $pdo->prepare("SELECT id_muestra, neto FROM muestras_detalle WHERE reporte_id = ? AND UPPER(id_muestra) != 'CA'");
        $stmtM->execute([$reporteId]);
    }
    while ($m = $stmtM->fetch(PDO::FETCH_ASSOC)) {
        $resultado[strtoupper($m['id_muestra'])] = $m['neto'];
    }
}

echo json_encode($resultado);
