<?php

/////////////////////////////////////////////////////////////////////////////////////////////////////
// Al inicio de cualquier página de tu sitio:
date_default_timezone_set('America/Argentina/Buenos_Aires');

$ip = $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
$dispositivo = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido', 0, 255);

include 'backend/database.php'; // $conn (mysqli) -> base de datos lab_tritio_v2
$pagina = $_SERVER['PHP_SELF'];

$stmtVisita = $conn->prepare("INSERT INTO registro_visitas (ip_usuario, dispositivo, fecha_acceso, accion_realizada) VALUES (?, ?, NOW(), ?)");
$accion = 'Visito: ' . $pagina;
$stmtVisita->bind_param('sss', $ip, $dispositivo, $accion);
$stmtVisita->execute();
$stmtVisita->close();

$baseestado = 'ip';
$sql1b = "SELECT IP FROM $baseestado WHERE ID='1'";
$result = mysqli_query($conn, $sql1b);
$dato1 = '';
while ($row1 = mysqli_fetch_assoc($result)) {
    $dato1 = $row1['IP'] . "";
}
$dato = $dato1;
$direccion = $dato;

// --- CATÁLOGO DINÁMICO DE MUESTRAS ---
// Reemplaza la lista fija que tenía el proyecto original: cada ID nuevo que
// aparezca en un archivo cargado se agrega solo, sin tocar código.
$nombres_completos = [];
$resCat = $conn->query("SELECT sigla, nombre_completo FROM muestras_catalogo");
if ($resCat) {
    while ($rowCat = $resCat->fetch_assoc()) {
        $nombres_completos[$rowCat['sigla']] = $rowCat['nombre_completo'];
    }
}

function registrarEnCatalogoSiFalta($conn, &$nombres_completos, $id) {
    $id = strtoupper(trim($id));
    if ($id === '' || isset($nombres_completos[$id])) return false;
    if (preg_match('/bkg|fondo|h-?3/i', $id)) return false; // controles, no son "muestras" de ubicación
    $stmt = $conn->prepare("INSERT INTO muestras_catalogo (sigla, nombre_completo) VALUES (?, ?) ON DUPLICATE KEY UPDATE sigla = sigla");
    $stmt->bind_param('ss', $id, $id);
    $stmt->execute();
    $stmt->close();
    $nombres_completos[$id] = $id;
    return true;
}
?>

<script>
function limpiarURLySubir(file) {
    const url = new URL(window.location.href);
    if (url.searchParams.has('cargar_fecha')) {
        url.searchParams.delete('cargar_fecha');
        window.history.replaceState({}, '', url.pathname);
    }
    const formData = new FormData();
    formData.append('archivo', file);
    fetch('', { method: 'POST', body: formData })
        .then(response => response.text())
        .then(html => {
            document.open();
            document.write(html);
            document.close();
        });
}

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);

    // 1. CARGA AUTOMÁTICA AL INICIAR
    if (!urlParams.has('cargar_fecha')) {
        const url = new URL(window.location.href);
        url.searchParams.set('cargar_fecha', '03/04/2024');
        window.location.href = url.toString();
        return;
    }

    // 2. RECUPERAR LÍMITES FIJOS DESDE LOCALSTORAGE
    const savedConfig = localStorage.getItem('config_limites');
    if (savedConfig) {
        const c = JSON.parse(savedConfig);
        if(document.getElementById('lld_val'))      document.getElementById('lld_val').value = c.lld;
        if(document.getElementById('lim_inf_val'))  document.getElementById('lim_inf_val').value = c.lim_inf;
        if(document.getElementById('lim_sup_val'))  document.getElementById('lim_sup_val').value = c.lim_sup;
        if(document.getElementById('bkg_max'))      document.getElementById('bkg_max').value = c.bkg_max;
        if(document.getElementById('bkg_min'))      document.getElementById('bkg_min').value = c.bkg_min;
        if(document.getElementById('h3_max'))       document.getElementById('h3_max').value = c.h3_max;
        if(document.getElementById('h3_min'))       document.getElementById('h3_min').value = c.h3_min;
    }

    // 3. LÓGICA DE LIMPIEZA
    if (sessionStorage.getItem('pendiente_limpiar_post_carga') === 'true') {
        limpiarSoloValores();
        sessionStorage.removeItem('pendiente_limpiar_post_carga');
        alert("Datos de referencia obtenidos. Formulario limpio para nueva carga.");
    }

    recalcularTodo();
});

// FUNCIÓN PRINCIPAL DEL BOTÓN
function iniciarCargaManual() {
    if (confirm("¿Desea limpiar el formulario para una nueva carga manual?")) {
        const inputTipo = document.getElementById('db_tipo_carga');
        if(inputTipo) inputTipo.value = "manual";

        sessionStorage.setItem('pendiente_limpiar_post_carga', 'true');
        sessionStorage.setItem('es_carga_manual', 'true');

        const url = new URL(window.location.href);
        url.searchParams.set('cargar_fecha', '03/04/2024');
        window.location.href = url.toString();
    }
}

function buscarPorFecha() {
    const fechaInput = document.getElementById('input_buscar_fecha').value;
    if(fechaInput) {
        const [anio, mes, dia] = fechaInput.split('-');
        const fechaFormateada = `${dia}/${mes}/${anio}`;
        window.location.href = "?cargar_fecha=" + encodeURIComponent(fechaFormateada);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const inputFecha = document.getElementById('input_buscar_fecha');
    if (inputFecha) {
        inputFecha.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                buscarPorFecha();
            }
        });
    }
});
</script>

<?php
$resultado = null;
$tabla_datos = [];
$fondos_detectados = [];
$muestras_agrupadas = [];
$datos_qc = ['bkg' => 0, 'h3_eff' => 0];
$semana_anio = date('W');
$lim_inf = 3.72;
$lim_sup = 4.85;
$lld_default = 8;

$host = 'localhost'; $db = 'lab_tritio_v2'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) { die("Error de conexión: " . $e->getMessage()); }

$esSubidaArchivo = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo']));

if (isset($_GET['cargar_fecha']) && $pdo && !$esSubidaArchivo) {
    $stmtBusca = $pdo->prepare("SELECT * FROM reportes_analisis WHERE fecha_archivo = ?");
    $stmtBusca->execute([$_GET['cargar_fecha']]);
    $reg = $stmtBusca->fetch(PDO::FETCH_ASSOC);

    if ($reg) {
        $resultado = [
            'model' => $reg['modelo'],
            'serial' => $reg['serial_n'],
            'tiempo' => $reg['tiempo'],
            'fecha' => $reg['fecha_archivo'],
            'comentarios' => $reg['comentarios'],
            'fecha_desde' => $reg['fecha_desde'],
            'fecha_hasta' => $reg['fecha_hasta'],
            'almafuerte_fecha_desde' => $reg['almafuerte_fecha_desde'],
            'almafuerte_fecha_hasta' => $reg['almafuerte_fecha_hasta'],
        ];
        $semana_anio = $reg['semana'];
        $lim_inf = $reg['lim_inf_fondo'];
        $lim_sup = $reg['lim_sup_fondo'];
        $datos_qc = ['bkg' => $reg['bkg_real'], 'h3_eff' => $reg['h3_real']];

        // 1. CARGAR FONDOS INDIVIDUALES
        $stmtF = $pdo->prepare("SELECT valor_bq FROM fondos_detalle WHERE reporte_id = ? ORDER BY numero_fondo ASC");
        $stmtF->execute([$reg['id']]);
        $fondos_detectados = [];
        while ($f = $stmtF->fetch(PDO::FETCH_ASSOC)) {
            $fondos_detectados[] = ['valor' => (float)$f['valor_bq']];
        }

        // 2. CARGAR MUESTRAS CON SUS 3 LECTURAS
        $stmtM = $pdo->prepare("SELECT id_muestra, neto, es_limite, lectura1, lectura2, lectura3 FROM muestras_detalle WHERE reporte_id = ?");
        $stmtM->execute([$reg['id']]);
        while ($m = $stmtM->fetch(PDO::FETCH_ASSOC)) {
            $muestras_agrupadas[$m['id_muestra']] = [
                'lecturas' => [
                    $m['lectura1'] !== null ? (float)$m['lectura1'] : '',
                    $m['lectura2'] !== null ? (float)$m['lectura2'] : '',
                    $m['lectura3'] !== null ? (float)$m['lectura3'] : ''
                ],
                'neto' => $m['neto'],
                'es_limite' => $m['es_limite']
            ];
        }
    }
}

$historico_muestras = [];
$historico_qc = [];
if ($pdo) {
    $stmtQC = $pdo->query("SELECT * FROM reportes_analisis ORDER BY STR_TO_DATE(fecha_archivo, '%d/%m/%Y') ASC LIMIT 30");
    $historico_qc = $stmtQC->fetchAll(PDO::FETCH_ASSOC);
    $stmtNetos = $pdo->query("SELECT r.fecha_archivo, m.id_muestra, m.neto FROM muestras_detalle m JOIN reportes_analisis r ON m.reporte_id = r.id ORDER BY STR_TO_DATE(r.fecha_archivo, '%d/%m/%Y') ASC");
    while ($row = $stmtNetos->fetch(PDO::FETCH_ASSOC)) {
        $historico_muestras[$row['id_muestra']][] = ['x' => $row['fecha_archivo'], 'y' => (float)$row['neto']];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_borrar_bd'])) {
    try {
        $stmtId = $pdo->prepare("SELECT id FROM reportes_analisis WHERE fecha_archivo = ? AND modelo = ? AND serial_n = ?");
        $stmtId->execute([$_POST['db_fecha'], $_POST['db_modelo'], $_POST['db_serial']]);
        $reporteId = $stmtId->fetchColumn();

        if ($reporteId) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM datos_coinc WHERE id_reporte = ?")->execute([$reporteId]);
            $pdo->prepare("DELETE FROM muestras_detalle WHERE reporte_id = ?")->execute([$reporteId]);
            $pdo->prepare("DELETE FROM reportes_analisis WHERE id = ?")->execute([$reporteId]);
            $pdo->commit();
            echo "<script>alert('Registro y datos de coincidencia eliminados.'); window.location.href = window.location.pathname;</script>";
            exit;
        }
    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_guardar_bd'])) {
    try {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM reportes_analisis WHERE fecha_archivo = ? AND modelo = ? AND serial_n = ?");
        $checkStmt->execute([$_POST['db_fecha'], $_POST['db_modelo'], $_POST['db_serial']]);

        if ($checkStmt->fetchColumn() > 0) {
            echo "<script>alert('Error: Ya existe un registro para esta fecha. No se guardará.');</script>";
        } else {
            $pdo->beginTransaction();
            $tipo_carga = $_POST['db_tipo_carga'] ?? 'archivo';

            $stmt = $pdo->prepare("INSERT INTO reportes_analisis
                (fecha_archivo, modelo, serial_n, tiempo, semana, lim_inf_fondo, lim_sup_fondo, promedio_fondo, bkg_max, bkg_min, h3_max, h3_min, bkg_real, h3_real, comentarios, fecha_desde, fecha_hasta, almafuerte_fecha_desde, almafuerte_fecha_hasta, tipo_carga)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $_POST['db_fecha'], $_POST['db_modelo'], $_POST['db_serial'], $_POST['db_tiempo'],
                $_POST['db_semana'], $_POST['db_lim_inf'], $_POST['db_lim_sup'], $_POST['db_prom_fondo'],
                $_POST['db_bkg_max'], $_POST['db_bkg_min'], $_POST['db_h3_max'], $_POST['db_h3_min'],
                $_POST['db_bkg_real'], $_POST['db_h3_real'], $_POST['db_comentarios'],
                $_POST['db_fecha_desde'] ?? '', $_POST['db_fecha_hasta'] ?? '',
                $_POST['db_almafuerte_desde'] ?? '', $_POST['db_almafuerte_hasta'] ?? '',
                $tipo_carga
            ]);

            $reporteId = $pdo->lastInsertId();

            if (isset($_POST['fondo_valores'])) {
                $stmtF = $pdo->prepare("INSERT INTO fondos_detalle (reporte_id, numero_fondo, valor_bq) VALUES (?, ?, ?)");
                foreach ($_POST['fondo_valores'] as $idx => $valor) {
                    if ($valor !== "") {
                        $stmtF->execute([$reporteId, $idx + 1, $valor]);
                    }
                }
            }

            if (isset($_POST['m_id']) && isset($_POST['m_neto'])) {
                $stmtM = $pdo->prepare("INSERT INTO muestras_detalle (reporte_id, id_muestra, neto, es_limite, lectura1, lectura2, lectura3) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['m_id'] as $idx => $id_m) {
                    $valor_neto = $_POST['m_neto'][$idx];
                    $valor_final = ($valor_neto === "" || $valor_neto === null) ? null : $valor_neto;
                    $es_lim = $_POST['m_es_limite'][$idx] ?? 0;

                    $l1 = !empty($_POST['m_l1'][$idx]) ? (float)$_POST['m_l1'][$idx] : null;
                    $l2 = !empty($_POST['m_l2'][$idx]) ? (float)$_POST['m_l2'][$idx] : 0;
                    $l3 = !empty($_POST['m_l3'][$idx]) ? (float)$_POST['m_l3'][$idx] : 0;

                    $stmtM->execute([$reporteId, $id_m, $valor_final, $es_lim, $l1, $l2, $l3]);
                }
            }

            $pdo->commit();
            echo "<script>alert('¡Reporte, Fondos y Muestras guardados!'); window.location.href = window.location.pathname;</script>";
        }
    } catch (Exception $e) {
        if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

error_reporting(E_ALL); ini_set('display_errors', 1);
function obtenerTextoLimpio($file_path, $extension) {
    if ($extension === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($file_path)) { $xml = $zip->getFromName('word/document.xml'); $zip->close(); if (!$xml) return false; $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml); return html_entity_decode(strip_tags($xml)); }
    }
    if ($extension === 'rtf') {
        $text = file_get_contents($file_path); if (!$text) return false;
        $reemplazos = ['\par' => "\n", '\line' => "\n", '\row' => "\n", '\tab' => ' ', '\cell' => ' '];
        $text = str_replace(array_keys($reemplazos), array_values($reemplazos), $text);
        $text = preg_replace('/\\\\[a-z]+\d*(?:\s)?/i', '', $text); return str_replace(['{', '}'], '', $text);
    }
    return false;
}

// Mensaje informativo (no bloqueante) cuando se agregan IDs nuevos al catálogo
$mensaje_nuevas_muestras = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $tmp = $_FILES['archivo']['tmp_name']; $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
    $contenido = obtenerTextoLimpio($tmp, $ext);
    if ($contenido) {
        $contenido = preg_replace('/[ \t]+/', ' ', $contenido);
        preg_match('/MODEL[:\s=]+([^\n\r]+)/i', $contenido, $mModel); preg_match('/SERIAL[:\s=]+(\d+)/i', $contenido, $mSerial); preg_match('/Count Time\s*\(min\)[:\s=]+([\d\.]+)/i', $contenido, $mTime);
        $fechaFinal = date('d/m/Y');
        if (preg_match('/(\d{4})(\d{2})(\d{2})_\d{4}/', $contenido, $mFechaPath)) { $fechaFinal = $mFechaPath[3]."/".$mFechaPath[2]."/".$mFechaPath[1]; }
        elseif (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $contenido, $mFechaStd)) { $fechaFinal = $mFechaStd[1]; }
        $resultado = ['model' => trim($mModel[1] ?? 'Tri-Carb 3110TR'), 'serial' => trim($mSerial[1] ?? 'N/A'), 'tiempo' => trim($mTime[1] ?? 'N/A'), 'fecha' => $fechaFinal];
        $textoPlano = preg_replace('/\s+/', ' ', $contenido);
        preg_match_all('/\b(\d+)\s+([A-Za-z0-9\-]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+(\d+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)/', $textoPlano, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $id = trim($m[2]); $eff = $m[9]; $bq = $m[10]; $esFondo = (bool)preg_match('/fondo/i', $id); $cpma = $m[4];
            if ($esFondo) $fondos_detectados[] = ['valor' => (float)$bq];
            if (preg_match('/bkg/i', $id)) {
                $datos_qc['bkg'] = $cpma;
            }
            if (preg_match('/H-?3/i', $id)) $datos_qc['h3_eff'] = $eff;
            $tabla_datos[] = ['id' => $id, 'cpma' => $cpma, 'bq' => $bq, 'eff' => $eff, 'esFondo' => $esFondo];
            if (!$esFondo && !preg_match('/bkg|h-?3/i', $id)) {
                $muestras_agrupadas[$id] = [
                    'lecturas' => [(float)$bq],
                    'neto' => 0,
                    'es_limite' => 0
                ];
                // CATÁLOGO DINÁMICO: si el ID no estaba registrado, se agrega solo
                if (registrarEnCatalogoSiFalta($conn, $nombres_completos, $id)) {
                    $mensaje_nuevas_muestras[] = strtoupper(trim($id));
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Tri-Carb Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom"></script>
    <style>
        :root { --bg-body: #0f172a; --bg-input: #0f172a; --accent-blue: #38bdf8; --border-blue: #0ea5e9; }
        body { background-color: var(--bg-body); color: #f8fafc; }
        input, select { background-color: var(--bg-input) !important; color: white !important; border: 2px solid var(--border-blue) !important; border-radius: 6px; transition: all 0.2s ease !important; outline: none !important; }
        input:focus { border-color: #7dd3fc !important; box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.3) !important; background-color: #1e293b !important; color: white !important; }
        .input-modificado { background-color: rgba(254, 240, 138, 0.5) !important; border: 2px solid #eab308 !important; color: white !important; }
        .input-error { background-color: rgba(239, 68, 68, 0.2) !important; border: 2px solid #ef4444 !important; }
        .titilando-alerta {  animation: titilado-extremo 0.6s infinite alternate; font-weight: 900; }
        @keyframes titilado-extremo { from { background-color: rgba(127, 29, 29, 0.3); border-color: #ef4444; } to { background-color: rgb(205, 6, 69); border-color: #fecaca; } }
        .drop-active { border-color: #38bdf8 !important; background-color: rgba(56, 189, 248, 0.1) !important; transform: scale(1.02); }
        .btn-guardar { background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); border: 1px solid rgba(255,255,255,0.1); }
        .btn-guardar:hover { filter: brightness(1.1); box-shadow: 0 0 15px rgba(56, 189, 248, 0.4); transform: translateY(-1px); }
        #zoomModal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.98); z-index: 9999; flex-direction: column; padding: 2rem; }
        #zoomModal.active { display: flex; }

        .btn-eliminar-fila { color: #ef4444; transition: all 0.2s; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .btn-eliminar-fila:hover { transform: scale(1.2); color: #f87171; }

        @media print {
            @page { size: A4; margin: 0.5cm; }
            body { background-color: white !important; color: black !important; display: block !important; overflow: visible !important; width: 100%; height: auto; }
            .no-print { display: none !important; }
            body > div { width: 100% !important; height: auto !important; float: none !important; position: static !important; border: none !important; padding: 0 !important; margin: 0 !important; overflow: visible !important; }
            .flex { display: block !important; }
            .w-1\/4, .w-2\/4 { width: 100% !important; min-width: 100% !important; background: transparent !important; border: none !important; height: auto !important; overflow: visible !important; padding: 5px !important; }
            .bg-slate-900, .bg-slate-950 { background: transparent !important; }
            h1, h2, h3, span, td, th { color: black !important; }
            input { border: 1px solid #ccc !important; color: black !important; background: transparent !important; font-size: 10px !important; height: auto !important; padding: 2px !important; }
            table { border-collapse: collapse !important; width: 100% !important; margin-bottom: 10px; }
            th, td { border: 1px solid #ddd !important; padding: 4px !important; font-size: 9px !important; }
            canvas { max-width: 100% !important; height: 180px !important; margin: 0 auto; display: block; }
            .space-y-6 > div { page-break-inside: avoid; margin-bottom: 15px; border: 1px solid #eee !important; }
        }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }

        .alerta-rango { background-color: rgba(239, 68, 68, 0.2) !important; border: 2px solid #ef4444 !important; animation: titilado-alerta 0.8s infinite alternate; }
        @keyframes titilado-alerta { from { opacity: 1; } to { opacity: 0.6; } }

        .del-container { position: relative; display: inline-block; width: 100%; }
        .btn-del-mini { color: red; border: none; background: none; cursor: pointer; font-weight: bold; position: absolute; right: 2px; top: -5px; font-size: 12px; }
        .btn-del-mini:hover { color: darkred; transform: scale(1.2); }
    </style>
</head>

<body class="min-h-screen font-sans flex overflow-hidden">

<?php if (!empty($mensaje_nuevas_muestras)): ?>
    <script>
        alert("Se detectaron IDs de muestra nuevos y se agregaron automáticamente al catálogo: <?php echo implode(", ", $mensaje_nuevas_muestras); ?>");
    </script>
<?php endif; ?>

<div id="zoomModal" class="no-print">
    <div class="flex justify-between items-center mb-6">
        <h2 id="modalTitle" class="text-2xl font-black italic uppercase text-sky-400">Análisis Detallado</h2>
        <button onclick="closeZoom()" class="bg-rose-600 hover:bg-rose-500 text-white px-6 py-2 rounded-lg font-bold uppercase transition-colors">Cerrar [ESC]</button>
    </div>
    <div class="flex-1 bg-slate-900 rounded-2xl border border-slate-800 p-4 relative shadow-2xl">
        <canvas id="modalCanvas"></canvas>
    </div>
</div>

<div class="w-1/4 min-w-[350px] bg-slate-900 border-r border-slate-800 h-screen p-6 overflow-y-auto">
    <h1 class="text-2xl font-black mb-3 italic uppercase tracking-tighter">CONCENTRACION  <span class="text-sky-400">TRITIO EN AGUA</span></h1>

    <form method="POST" enctype="multipart/form-data" id="uploadForm" class="no-print">
        <div id="dropZone" class="group relative bg-slate-800 border-2 border-dashed border-slate-700 rounded-2xl p-2 text-center cursor-pointer transition-all hover:bg-slate-700"
             onclick="document.getElementById('fileInput').click()" ondragover="event.preventDefault(); this.classList.add('drop-active')" ondragleave="this.classList.remove('drop-active')" ondrop="handleDrop(event)">
            <input type="file" name="archivo" id="fileInput" class="hidden" accept=".rtf,.docx" onchange="document.getElementById('uploadForm').submit()">
            <div class="flex flex-col items-center pointer-events-none">
                <div class="mb-3 p-0.5 bg-sky-100/10 rounded-full text-sky-400 group-hover:bg-sky-500 group-hover:text-slate-900 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 22 22" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                </div>
                <p class="text-sm font-bold text-slate-300">Arrastrar o Cargar Archivo de Reporte (.rtf / .docx)</p>
            </div>
        </div>
    </form>

<div class="mb-4 no-print bg-slate-800/50 p-0.5 rounded-xl border border-slate-700">
    <label class="text-[9px] font-black text-sky-500 uppercase italic mb-1 block ">
        Cargar Reporte de Forma Manual
    </label>
    <div class="flex gap-2 justify-center">
        <button type="button" onclick="iniciarCargaManual()" class="bg-sky-600 hover:bg-sky-500 px-3 py-1 rounded text-[10px] font-bold uppercase">
            ✨ Botón Carga Manual (Vaciar)
        </button>
    </div><div style="height: 5px;"></div>
</div>

    <div class="mb-4 no-print bg-slate-800/50 p-1 rounded-xl border border-slate-700">
        <label class="text-[9px] font-black text-sky-500 uppercase italic mb-1 block">Visualizar Reporte por Fecha</label>
        <div class="flex gap-2">
    <input type="date" id="input_buscar_fecha" class="flex-1 text-xs px-2 py-1 pr-1 text-white">
    <button type="button" onclick="buscarPorFecha()" class="bg-sky-600 hover:bg-sky-500 px-3 py-1 rounded text-[10px] font-bold uppercase">Cargar</button>
</div>
    </div>

    <?php if ($resultado): ?>
    <form method="POST" id="mainForm">

<input type="hidden" name="db_tipo_carga" id="db_tipo_carga" value="archivo">

<div class="bg-slate-800/80 border border-orange-400/30 p-1 rounded-lg shadow-inner mt-2">
    <label class="text-[9px] font-black text-orange-400/80 uppercase italic mb-1 block leading-none">Fecha Desde</label>
    <input type="text"
           name="db_fecha_desde"
           id="fecha_desde"
           value="<?= isset($resultado['fecha_desde']) ? $resultado['fecha_desde'] : '' ?>"
           placeholder="dd/mm/aaaa"
           pattern="\d{2}/\d{2}/\d{4}"
           title="El formato debe ser dd/mm/aaaa"
           onkeyup="mascaraFecha(this)"
           class="text-[11px] px-2 py-1 bg-slate-900 border-sky-500/50 text-white w-full">
</div>

<div class="bg-slate-800/80 border border-orange-400/30 p-1 rounded-lg shadow-inner mt-2">
    <label class="text-[9px] font-black text-orange-400/80 uppercase italic mb-1 block leading-none">Fecha Hasta</label>
    <input type="text"
           name="db_fecha_hasta"
           id="fecha_hasta"
           value="<?= isset($resultado['fecha_hasta']) ? $resultado['fecha_hasta'] : '' ?>"
           placeholder="dd/mm/aaaa"
           pattern="\d{2}/\d{2}/\d{4}"
           title="El formato debe ser dd/mm/aaaa"
           onkeyup="mascaraFecha(this)"
           class="text-[11px] px-2 py-1 bg-slate-900 border-sky-500/50 text-white w-full">
</div>

<div class="bg-slate-800/80 border border-emerald-400/30 p-1 rounded-lg shadow-inner mt-2">
    <label class="text-[9px] font-black text-emerald-400/80 uppercase italic mb-1 block leading-none">Almafuerte - Fecha Desde</label>
    <input type="text"
           name="db_almafuerte_desde"
           id="almafuerte_desde"
           value="<?= isset($resultado['almafuerte_fecha_desde']) ? $resultado['almafuerte_fecha_desde'] : '' ?>"
           placeholder="dd/mm/aaaa"
           pattern="\d{2}/\d{2}/\d{4}"
           title="El formato debe ser dd/mm/aaaa"
           onkeyup="mascaraFecha(this)"
           class="text-[11px] px-2 py-1 bg-slate-900 border-emerald-500/50 text-white w-full">
</div>

<div class="bg-slate-800/80 border border-emerald-400/30 p-1 rounded-lg shadow-inner mt-2">
    <label class="text-[9px] font-black text-emerald-400/80 uppercase italic mb-1 block leading-none">Almafuerte - Fecha Hasta</label>
    <input type="text"
           name="db_almafuerte_hasta"
           id="almafuerte_hasta"
           value="<?= isset($resultado['almafuerte_fecha_hasta']) ? $resultado['almafuerte_fecha_hasta'] : '' ?>"
           placeholder="dd/mm/aaaa"
           pattern="\d{2}/\d{2}/\d{4}"
           title="El formato debe ser dd/mm/aaaa"
           onkeyup="mascaraFecha(this)"
           class="text-[11px] px-2 py-1 bg-slate-900 border-emerald-500/50 text-white w-full">
</div>

        <div class="mt-4 rounded-xl border border-slate-700 overflow-hidden bg-slate-800/30">
            <table class="w-full text-center text-[11px]">
                <thead class="bg-slate-800 text-slate-400 font-bold uppercase"><tr><th class="p-2">Fondo</th><th class="p-2 border-l border-slate-700">Bq/L</th></tr></thead>
                <tbody class="divide-y divide-slate-700">
                    <?php for ($i = 0; $i < 5; $i++): $bq_val = $fondos_detectados[$i]['valor'] ?? ''; ?>
<tr>
    <td class="p-2 text-slate-500 font-bold italic">F <?= $i+1 ?></td>
    <td class="p-0 border-l border-slate-700">
        <input type="number" name="fondo_valores[]" step="0.01" value="<?= $bq_val ?>" data-original="<?= $bq_val ?>" class="text-center w-full font-bold py-1 input-field-bkg input-monitor" oninput="recalcularTodo()">
    </td>
</tr>
<?php endfor; ?>
                </tbody>
            </table>
        </div>

        <input type="hidden" name="db_fecha" value="<?= $resultado['fecha'] ?>">
        <input type="hidden" name="db_tiempo" value="<?= $resultado['tiempo'] ?>">
        <input type="hidden" name="db_prom_fondo" id="input_hidden_prom">
        <input type="hidden" name="db_lim_inf" id="input_hidden_lim_inf">
        <input type="hidden" name="db_lim_sup" id="input_hidden_lim_sup">

        <div class="mt-4 bg-slate-800/50 border border-slate-700 rounded-xl p-0.1">
            <div class="grid grid-cols-2 gap-2 mb-3">
                <div class="text-center">
                    <label class="text-[8px] font-black text-yellow-500 uppercase">Semana</label>
                    <input type="number" name="db_semana" value="<?= $semana_anio ?>" data-original="<?= $semana_anio ?>" class="w-full text-center font-bold text-sky-400 rounded py-0.3 mt-1 input-monitor" oninput="this.classList.add('manual-edit'); recalcularTodo()">
                </div>
                <div class="text-center">
                    <label class="text-[8px] font-black text-emerald-400 uppercase">Valor LLD</label>
                    <input id="lld_val" type="number" step="0.01" value="<?= $lld_default ?>" class="w-full text-center font-bold rounded py-0.3 mt-1 text-emerald-400 border-emerald-500/50" oninput="recalcularTodo()">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mb-3">
                <div class="text-center"><label class="text-[8px] font-black text-orange-400 uppercase">Lím. Inf. Fondo</label><input id="lim_inf_val" type="number" value="<?= $lim_inf ?>" data-original="<?= $lim_inf ?>" class="w-full text-center font-bold rounded py-0.3 mt-1 input-monitor" oninput="recalcularTodo()"></div>
                <div class="text-center"><label class="text-[8px] font-black text-orange-400 uppercase">Lím. Sup. Fondo</label><input id="lim_sup_val" type="number" value="<?= $lim_sup ?>" data-original="<?= $lim_sup ?>" class="w-full text-center font-bold rounded py-0.3 mt-1 input-monitor" oninput="recalcularTodo()"></div>
            </div>
            <div class="p-0.05 bg-sky-600 rounded-lg flex justify-between items-center text-slate-900 shadow-lg"><span class="text-[15px] font-black uppercase">Promedio de Fondo</span><span id="promedio_display" class="text-xl font-black">0.00</span></div>
        </div>

        <div class="mt-4 bg-slate-900 rounded-xl border border-slate-700 overflow-hidden text-[8px]">
            <table class="w-full">
                <tr class="border-b border-slate-800"><td class="p-1.5 font-bold text-slate-500 uppercase leading-none text-[10px]">BKG MAX/MIN</td><td class="p-0.3 flex gap-1"><input id="bkg_max" name="db_bkg_max" type="number" value="4.85" data-original="4.85" class="w-full text-center rounded py-0.3 font-bold text-orange-400 input-monitor" oninput="recalcularTodo()"><input id="bkg_min" name="db_bkg_min" type="number" value="3.72" data-original="3.72" class="w-full text-center rounded py-1 font-bold text-sky-400 input-monitor" oninput="recalcularTodo()"></td></tr>
                <tr class="border-b border-slate-800 bg-slate-800/20">
    <td class="p-1.5 font-bold text-slate-500 uppercase leading-none text-[10px]">VALOR BKG</td>
    <td class="p-0" style="position: relative;">
    <div style="display: flex; align-items: center;">
        <input type="text" id="bkg_val_cell" name="db_bkg_real"
               value="<?= $datos_qc['bkg'] ?>" data-original="<?= $datos_qc['bkg'] ?>"
               class="w-full text-center bg-transparent border-none font-black text-sm text-white focus:ring-0"
               oninput="this.value = this.value.replace(',', '.'); recalcularTodo()">
        <button type="button" onclick="borrarDatoQC('bkg_val_cell')"
                style="color: #ff4444; border: none; background: none; cursor: pointer; font-weight: bold; position: absolute; right: 5px; z-index: 10; font-size: 16px;">×</button>
    </div>
</td>
</tr>
                <tr class="border-b border-slate-800"><td class="p-1.5 font-bold text-slate-500 uppercase leading-none text-[10px]">H3 MAX/MIN</td><td class="p-0.3 flex gap-1"><input id="h3_max" name="db_h3_max" type="number" value="0.5901" data-original="0.5901" class="w-full text-center rounded py-0.3 font-bold text-orange-400 input-monitor" oninput="recalcularTodo()"><input id="h3_min" name="db_h3_min" type="number" value="0.584" data-original="0.584" class="w-full text-center rounded py-1 font-bold text-sky-400 input-monitor" oninput="recalcularTodo()"></td></tr>
                <tr class="bg-slate-800/20">
    <td class="p-1.5 font-bold text-slate-500 uppercase leading-none text-[10px]">VALOR H3</td>
    <td class="p-0" style="position: relative;">
    <div style="display: flex; align-items: center;">
        <input type="text" id="h3_val_cell" name="db_h3_real"
               value="<?= $datos_qc['h3_eff'] ?>" data-original="<?= $datos_qc['h3_eff'] ?>"
               class="w-full text-center bg-transparent border-none font-black text-sm text-white focus:ring-0"
               oninput="this.value = this.value.replace(',', '.'); recalcularTodo()">
        <button type="button" onclick="borrarDatoQC('h3_val_cell')"
                style="color: #ff4444; border: none; background: none; cursor: pointer; font-weight: bold; position: absolute; right: 5px; z-index: 10; font-size: 16px;">×</button>
    </div>
</td>
</tr>
            </table>
        </div>

        <div class="flex gap-2 mt-6 no-print">
            <button type="submit" name="btn_guardar_bd" class="flex-1 btn-guardar text-white font-black py-4 rounded-xl uppercase text-[10px]">Guardar Datos</button>
            <button type="button" onclick="confirmarBorrado()" class="flex-1 bg-rose-700 hover:bg-rose-600 text-white font-black py-4 rounded-xl shadow-lg uppercase text-[10px]">Borrar Registro</button>
            <button type="button" onclick="window.print()" class="flex-1 bg-slate-700 hover:bg-slate-800 text-white font-black py-4 rounded-xl uppercase text-[10px]">Imprimir Todo</button>
        </div>
        <input type="submit" name="btn_borrar_bd" id="hiddenDeleteBtn" class="hidden">
</div>

<div class="w-2/4 h-screen pt-2 px-6 pb-6 overflow-y-auto bg-slate-950">

    <div class="mb-2 flex justify-between items-end border-b border-slate-800 pb-2 italic font-black uppercase">
<div class="flex flex-col items-center">
    <span class="text-slate-500 text-[10px]">Modelo del Equipo</span>
    <input type="text" name="db_modelo"
           value="<?= $resultado['model'] ?>"
           data-original="<?= $resultado['model'] ?>"
           class="bg-transparent border-b border-sky-500/30 text-white text-xl tracking-tighter text-center focus:border-sky-400 outline-none w-full transition-all input-monitor"
           oninput="recalcularTodo()">
</div>
<div class="flex flex-col items-center">
    <span class="text-slate-500 text-[10px]">Número de Serie</span>
    <input type="text" name="db_serial"
           value="<?= $resultado['serial'] ?>"
           data-original="<?= $resultado['serial'] ?>"
           class="bg-transparent border-b border-sky-500/30 text-white text-xl tracking-tighter text-center focus:border-sky-400 outline-none w-full transition-all input-monitor"
           oninput="recalcularTodo()">
</div>

        <div class="flex flex-col items-center">
    <span class="text-sky-500 text-[10px]">Fecha Reporte</span>
    <input type="text"
           name="db_fecha"
           value="<?= $resultado['fecha'] ?>"
           class="bg-transparent border-b border-sky-500/30 text-sky-400 text-xl tracking-tighter not-italic text-right focus:border-sky-400 focus:bg-slate-800/50 outline-none w-32 transition-all"
           placeholder="dd/mm/aaaa"
           pattern="\d{2}/\d{2}/\d{4}"
           title="El formato debe ser dd/mm/aaaa"
           onkeyup="mascaraFecha(this)">
</div>
</div>

<?php if (isset($reg['tipo_carga']) && $reg['tipo_carga'] === 'manual'): ?>
    <div class="mb-4 mx-6 bg-orange-500/20 border-2 border-orange-500 p-3 rounded-xl flex items-center gap-3 animate-pulse no-print">
        <span class="text-2xl">✍️</span>
        <div>
            <h4 class="text-orange-400 font-black uppercase text-xs">Aviso de Integridad</h4>
            <p class="text-white text-[10px] font-bold">ESTE REPORTE FUE INGRESADO MEDIANTE CARGA MANUAL.</p>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($resultado['comentarios'])): ?>
    <div class="flex justify-end mb-2">
        <div class="flex items-center gap-2 bg-yellow-400 border-2 border-yellow-200 px-4 py-1.5 rounded-lg shadow-[0_0_15px_rgba(250,204,21,0.6)] animate-pulse">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-900" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
            </svg>
            <span class="text-[11px] font-black text-slate-900 uppercase tracking-wider">
                ⚠️ Reporte con Observaciones
            </span>
        </div>
    </div>
<?php endif; ?>

<div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden shadow-2xl">
    <table class="w-full">
            <thead class="bg-slate-800 text-slate-400 text-[10px] font-black uppercase">
                <tr><th class="px-4 py-3 text-left">ID Muestra</th><th class="text-center">L1</th><th class="text-center">L2</th><th class="text-center">L3</th><th class="text-center bg-slate-700/30 text-sky-300">Promedio de M.</th><th class="text-center bg-emerald-900/30 text-emerald-400">Bq/L (PM - PF)</th><th class="px-2 no-print"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($muestras_agrupadas as $id => $datos):
                    $valores = $datos['lecturas'];
                    $esLimiteBD = isset($datos['es_limite']) && $datos['es_limite'] == 1;
                ?>
                <tr class="row-muestra border-b border-slate-800">
                    <td class="p-2 font-black text-sky-400 border-r border-slate-800 text-left">
    <?php
        echo isset($nombres_completos[$id])
             ? $id . " (" . $nombres_completos[$id] .")"
             : $id;
    ?>
    <input type="hidden" name="m_id[]" value="<?= $id ?>">
</td>
                    <?php
for ($j = 0; $j < 3; $j++):
    $v = $valores[$j] ?? '';
    $nombreLectura = "m_l" . ($j + 1) . "[]";
?>
    <td class="p-1 border-l border-slate-800">
    <input type="text"
       name="<?= $nombreLectura ?>"
       value="<?= ($v == 0 && $v !== '') ? '' : $v ?>"
       data-original="<?= $v ?>"
       class="w-full py-2 text-center font-bold text-white input-muestra text-xs input-monitor"
       oninput="this.value = this.value.replace(',', '.'); recalculateManual(this)">
</td>
<?php endfor; ?>
                    <td class="px-4 py-1 text-center bg-slate-800/50 font-bold text-sky-300 text-xs cell-promedio">0.00</td>
                    <td class="px-4 py-1 text-center bg-emerald-900/10 font-black text-emerald-400 text-xs cell-neto">0.00</td>
                    <input type="hidden" name="m_neto[]" class="hidden-neto">
                    <input type="hidden" name="m_es_limite[]" class="hidden-es-limite" value="<?= $esLimiteBD ? '1' : '0' ?>">
                    <td class="px-2 text-center no-print">
                        <button type="button" onclick="eliminarFila(this)" class="btn-eliminar-fila" title="Limpiar Datos">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 bg-slate-900/50 rounded-xl border border-slate-800 p-4">
        <label class="text-[10px] font-black text-sky-500 uppercase italic mb-2 block">Notas y Observaciones del Reporte</label>
        <textarea
            name="db_comentarios"
            class="w-full bg-slate-950 border-2 border-slate-800 rounded-lg p-3 text-xs text-slate-300 focus:border-sky-500 outline-none transition-all resize-none shadow-inner"
            rows="3"
            placeholder="Ingrese comentarios sobre los resultados de esta fecha..."><?= $resultado['comentarios'] ?? '' ?></textarea>
    </div>

    <?php endif; ?> </div>

<div class="w-1/4 h-screen bg-slate-900 p-6 overflow-y-auto border-l border-slate-800">
    <?php if ($resultado): ?>
    <h2 class="text-[10px] font-black uppercase text-slate-500 mb-4 italic text-center">Raw Data Log</h2>
    <div class="rounded-xl border border-slate-800 overflow-hidden bg-slate-950 h-32 overflow-y-auto mb-6">
        <table class="w-full text-center text-[9px]">
            <thead class="bg-slate-800 text-slate-500 font-bold uppercase"><tr><th>ID</th><th>CPMA</th><th>EFF</th><th>Bq/L</th></tr></thead>
            <tbody class="divide-y divide-slate-800">
                <?php foreach ($tabla_datos as $f): ?>
                <tr><td class="text-left pl-2"><?= $f['id'] ?></td><td class="text-sky-500"><?= $f['cpma'] ?></td><td class="text-sky-500"><?= $f['eff'] ?></td><td class="text-white"><?= $f['bq'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
        <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-2">
                    <h3 class="text-[9px] font-black uppercase text-sky-400 italic">Muestras + Límites</h3>
                    <button type="button" onclick="openZoom('netos')" class="no-print text-sky-400 hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                    </button>
                </div>
                <select onchange="cambiarTipoGrafica('netos', this.value)" class="no-print text-[8px] px-1 bg-slate-800 border-slate-700">
                    <option value="smooth">Histórico (Curvas)</option>
                    <option value="bar">Actual (Barras)</option>
                    <option value="area">Actual (Área)</option>
                </select>
            </div>
            <canvas id="chartNetos" height="200"></canvas>
        </div>
        <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-2">
                    <h3 class="text-[9px] font-black uppercase text-orange-400 italic">bkg - h3 + Límites</h3>
                    <button type="button" onclick="openZoom('qc')" class="no-print text-orange-400 hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                    </button>
                </div>
                <select onchange="cambiarTipoGrafica('qc', this.value)" class="no-print text-[8px] px-1 bg-slate-800 border-slate-700">
                    <option value="smooth">Tendencia (Curvas)</option>
                    <option value="bar">Actual (Barras)</option>
                </select>
            </div>
            <canvas id="chartQC" height="200"></canvas>
        </div>
    </div>
    <?php endif; ?>
</div>
</form>

<script>
const historicoMuestras = <?= json_encode($historico_muestras) ?>;
const nombresCompletos = <?= json_encode($nombres_completos) ?>;
const historicoQC = <?= json_encode($historico_qc) ?>;
let chartNetos = null, chartQC = null, modalChart = null;
let tipoNetos = 'smooth', tipoQC = 'smooth';

function openZoom(tipo) {
    const modal = document.getElementById('zoomModal'),
          title = document.getElementById('modalTitle'),
          ctxModal = document.getElementById('modalCanvas').getContext('2d');
    modal.classList.add('active');
    if(modalChart) modalChart.destroy();
    const original = (tipo === 'netos') ? chartNetos : chartQC;
    title.innerText = (tipo === 'netos') ? 'ANÁLISIS DE VALORES NETOS' : 'CONTROL DE CALIDAD (QC)';
    modalChart = new Chart(ctxModal, {
    type: original.config.type,
    data: JSON.parse(JSON.stringify(original.data)),
    options: {
        responsive: true, maintainAspectRatio: false,
onClick: (e) => {
    const points = modalChart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true);
    if (points.length === 0) return;
    const datasetIndex = points[0].datasetIndex;
    const index = points[0].index;
    const datasetLabel = modalChart.data.datasets[datasetIndex].label;
    if (datasetLabel !== 'Prom. Fondo Hist.') return;
    const fechaSeleccionada = modalChart.data.labels[index];
    if (fechaSeleccionada) {
        window.location.href = "?cargar_fecha=" + encodeURIComponent(fechaSeleccionada);
    }
},
        onHover: (event, chartElement) => {
            event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
        },
        plugins: {
            legend: { display: true, labels: { color: '#f8fafc', font: { size: 12 } } },
            zoom: { zoom: { wheel: { enabled: true }, drag: { enabled: true, backgroundColor: 'rgba(56, 189, 248, 0.4)' }, mode: 'x' } }
        },
            scales: { y: { grid: { color: '#1e293b' }, ticks: { color: '#94a3b8' } }, x: { grid: { color: '#1e293b' }, ticks: { color: '#94a3b8' } } }
        }
    });
}

function closeZoom() { document.getElementById('zoomModal').classList.remove('active'); }
document.addEventListener('keydown', (e) => { if(e.key === "Escape") closeZoom(); });
function handleDrop(e) { e.preventDefault(); document.getElementById('dropZone').classList.remove('drop-active'); const files = e.dataTransfer.files; if (files.length > 0) { document.getElementById('fileInput').files = files; document.getElementById('uploadForm').submit(); } }
function cambiarTipoGrafica(g, t) { if(g==='netos') tipoNetos=t; else tipoQC=t; actualizarGraficas(); }

function actualizarGraficas() {
    const ctxNetos = document.getElementById('chartNetos'), ctxQC = document.getElementById('chartQC');
    if(!ctxNetos) return;
    const pF = parseFloat(document.getElementById('input_hidden_prom').value) || 0;
    const lInf = parseFloat(document.getElementById('lim_inf_val').value) || 0, lSup = parseFloat(document.getElementById('lim_sup_val').value) || 0;
    let datasetsNetos = [], finalLabelsNetos = [];
    const colores = ['#38bdf8', '#fb923c', '#4ade80', '#f472b6', '#a78bfa', '#40f10a', '#e06161'];

    if(tipoNetos === 'bar' || tipoNetos === 'area') {
        let dataActual = [];
document.querySelectorAll('.row-muestra').forEach(r => {
    finalLabelsNetos.push(r.querySelector('td').innerText);
    let v = r.querySelector('.hidden-neto').value;
    dataActual.push((v === "" || parseFloat(v) === 0) ? null : parseFloat(v));
});
        datasetsNetos.push({ label: 'Prom. Fondo', data: Array(finalLabelsNetos.length).fill(pF), borderColor: '#ea580c', borderWidth: 2, fill: false, pointRadius: 0, type: 'line' });
        datasetsNetos.push({ label: 'Lím. Inf', data: Array(finalLabelsNetos.length).fill(lInf), borderColor: '#fb923c', borderDash: [5, 5], fill: false, pointRadius: 0, type: 'line' });
        datasetsNetos.push({ label: 'Lím. Sup', data: Array(finalLabelsNetos.length).fill(lSup), borderColor: '#f87171', borderDash: [5, 5], fill: false, pointRadius: 0, type: 'line' });
        const esArea = (tipoNetos === 'area');
        const tipoDinamico = esArea ? 'line' : 'bar';
        datasetsNetos.push({
            label: 'Valor Actual',
            data: dataActual,
            backgroundColor: esArea ? 'rgba(56, 189, 248, 0.4)' : '#48a16d',
            borderColor: '#7bc45c',
            fill: esArea,
            type: tipoDinamico,
            tension: 0.4,
            spanGaps: true,
            tension: 0.4
        });
    } else {
        const visiblesPorDefault = ['CTM', 'CCR3', 'CFA', 'CVDD', 'CL', 'CA'];
        let i = 0, labelsH = [];
        for(let m in historicoMuestras) {
            datasetsNetos.push({
        label: nombresCompletos[m] ? (m + ' (' + nombresCompletos[m] + ')') : m,
        data: historicoMuestras[m].map(point => {
            return {
                x: point.x,
                y: point.y <= 0 ? null : point.y
            };
        }),
        borderColor: colores[i % colores.length],
        tension: 0.4,
        fill: false,
        hidden: !visiblesPorDefault.includes(m),
        spanGaps: true
    });
            historicoMuestras[m].forEach(d => { if(!labelsH.includes(d.x)) labelsH.push(d.x); });
            labelsH.sort((a,b)=>{
    const pa = a.split('/').reverse().join('-');
    const pb = b.split('/').reverse().join('-');
    return new Date(pa) - new Date(pb);
});
            i++;
        }
        datasetsNetos.push({ label: 'Prom. Fondo Hist.', data: labelsH.map(l => { const h = historicoQC.find(reg => reg.fecha_archivo === l); return {x: l, y: h ? parseFloat(h.promedio_fondo) : pF}; }), borderColor: '#ea580c', borderWidth: 2, fill: false, pointRadius: 2 });
        datasetsNetos.push({ label: 'Lím. Inf Guardado', data: labelsH.map(l => { const h = historicoQC.find(reg => reg.fecha_archivo === l); return {x: l, y: h ? parseFloat(h.lim_inf_fondo) : lInf}; }), borderColor: '#fb923c', borderDash: [5, 5], fill: false, pointRadius: 0 });
        datasetsNetos.push({ label: 'Lím. Sup Guardado', data: labelsH.map(l => { const h = historicoQC.find(reg => reg.fecha_archivo === l); return {x: l, y: h ? parseFloat(h.lim_sup_fondo) : lSup}; }), borderColor: '#f87171', borderDash: [5, 5], fill: false, pointRadius: 0 });
        finalLabelsNetos = labelsH;
    }

    if(chartNetos) chartNetos.destroy();
    chartNetos = new Chart(ctxNetos, {
        type: (tipoNetos === 'bar' ? 'bar' : 'line'),
        data: { labels: finalLabelsNetos, datasets: datasetsNetos },
        options: {
            onClick: (e, elements) => {
    if (elements.length === 0 || tipoNetos !== 'smooth') return;
    const datasetIndex = elements[0].datasetIndex;
    const index = elements[0].index;
    const datasetLabel = chartNetos.data.datasets[datasetIndex].label;
    if (datasetLabel !== 'Fondo Promedio' && datasetLabel !== 'Prom. Fondo Hist.') return;
    const fechaSeleccionada = chartNetos.data.labels[index];
    if(confirm("¿Cargar datos del " + fechaSeleccionada + "?")) {
        window.location.href = "?cargar_fecha=" + fechaSeleccionada;
    }
},
            responsive: true, plugins: { legend: { labels: { color: '#64748b', font: { size: 7 } } }, zoom: { zoom: { wheel: { enabled: true }, drag: { enabled: true }, mode: 'x' } } }, scales: { y: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } }, x: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } } }
        }
    });

    if(chartQC) chartQC.destroy();
    let dsQC = [], labelsQC = null;
    if(tipoQC === 'bar') {
        labelsQC = ['BKG', 'H3 Eff'];
        dsQC.push({ label: 'Valor Actual', data: [parseFloat(document.getElementById('bkg_val_cell').innerText), parseFloat(document.getElementById('h3_val_cell').innerText)], backgroundColor: ['#d65a5a', '#0ea5e9'], order: 2 });
        dsQC.push({ label: 'Lím. Max', data: [parseFloat(document.getElementById('bkg_max').value), parseFloat(document.getElementById('h3_max').value)], borderColor: '#fca5a5', borderWidth: 2, type: 'line', pointStyle: 'rectRot', fill: false, order: 1 });
        dsQC.push({ label: 'Lím. Min', data: [parseFloat(document.getElementById('bkg_min').value), parseFloat(document.getElementById('h3_min').value)], borderColor: '#7dd3fc', borderWidth: 2, type: 'line', pointStyle: 'rectRot', fill: false, order: 1 });
    } else {
        labelsQC = historicoQC.map(d => d.fecha_archivo);
        dsQC.push({ label: 'BKG Real', data: historicoQC.map(d => d.bkg_real), borderColor: '#ef4444', fill: false, tension: 0.3, spanGaps: true });
        dsQC.push({ label: 'H3 Real', data: historicoQC.map(d => d.h3_real), borderColor: '#0ea5e9', fill: false, tension: 0.3, spanGaps: true });
        dsQC.push({ label: 'BKG Max Hist.', data: historicoQC.map(d => d.bkg_max), borderColor: '#7f1d1d', borderDash: [3, 3], fill: false, pointRadius: 0, tension: 0 });
        dsQC.push({ label: 'BKG Min Hist.', data: historicoQC.map(d => d.bkg_min), borderColor: '#ef4444', borderDash: [3, 3], fill: false, pointRadius: 0, tension: 0 });
        dsQC.push({ label: 'H3 Max Hist.', data: historicoQC.map(d => d.h3_max), borderColor: '#0c4a6e', borderDash: [3, 3], fill: false, pointRadius: 0, tension: 0 });
        dsQC.push({ label: 'H3 Min Hist.', data: historicoQC.map(d => d.h3_min), borderColor: '#0ea5e9', borderDash: [3, 3], fill: false, pointRadius: 0, tension: 0 });
    }
    chartQC = new Chart(ctxQC, { type: tipoQC === 'bar' ? 'bar' : 'line', data: { labels: labelsQC, datasets: dsQC }, options: { responsive: true, plugins: { legend: { labels: { font: { size: 7 }, color: '#64748b' } }, zoom: { zoom: { wheel: { enabled: true }, drag: { enabled: true, backgroundColor: 'rgba(56, 189, 248, 0.3)' }, mode: 'x' } } }, scales: { y: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } }, x: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } } } } });
}

function recalcularTodo() {
    const formularios = document.getElementsByTagName('form');
    for (let i = 0; i < formularios.length; i++) {
        formularios[i].setAttribute('novalidate', true);
    }

    const idsCriticos = ['bkg_val_cell', 'h3_val_cell', 'lim_inf_val', 'lim_sup_val', 'bkg_max', 'bkg_min', 'h3_max', 'h3_min'];
    idsCriticos.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.removeAttribute('min');
            el.removeAttribute('max');
            if (typeof el.setCustomValidity === "function") {
                el.setCustomValidity('');
            }
        }
    });

    const fechaInput = document.querySelector('input[name="db_fecha"]');
    const semanaInput = document.querySelector('input[name="db_semana"]');
    if (fechaInput && fechaInput.value && !semanaInput.classList.contains('manual-edit')) {
        const partes = fechaInput.value.split('/');
        const date = new Date(partes[2], partes[1] - 1, partes[0]);
        if (!isNaN(date.getTime())) {
            const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
            const dayNum = d.getUTCDay() || 7;
            d.setUTCDate(d.getUTCDate() + 4 - dayNum);
            const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
            const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
            semanaInput.value = weekNo;
        }
    }

    const cellBkg = document.getElementById('bkg_val_cell');
    const cellH3 = document.getElementById('h3_val_cell');

    const bkgReal = parseFloat(cellBkg.value) || 0;
    const h3Real = parseFloat(cellH3.value) || 0;
    const bkgMax = parseFloat(document.getElementById('bkg_max').value) || 0;
    const bkgMin = parseFloat(document.getElementById('bkg_min').value) || 0;
    const h3Max = parseFloat(document.getElementById('h3_max').value) || 0;
    const h3Min = parseFloat(document.getElementById('h3_min').value) || 0;

    if (bkgReal > bkgMax || bkgReal < bkgMin) {
        cellBkg.classList.add('alerta-rango');
    } else {
        cellBkg.classList.remove('alerta-rango');
    }

    if (h3Real > h3Max || h3Real < h3Min) {
        cellH3.classList.add('alerta-rango');
    } else {
        cellH3.classList.remove('alerta-rango');
    }

    const camposConfig = ['lld_val', 'lim_inf_val', 'lim_sup_val', 'bkg_max', 'bkg_min', 'h3_max', 'h3_min', 'bkg_val_cell', 'h3_val_cell'];
    camposConfig.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.classList.remove('input-modificado');
if (el.dataset.original !== undefined && el.value !== "") {
    if (parseFloat(el.value) !== parseFloat(el.dataset.original)) {
        el.classList.add('input-modificado');
    }
}
        }
    });

    const lInf = parseFloat(document.getElementById('lim_inf_val').value) || 0;
    const lSup = parseFloat(document.getElementById('lim_sup_val').value) || 0;
    const LLD = parseFloat(document.getElementById('lld_val').value) || 0;

    document.getElementById('input_hidden_lim_inf').value = lInf;
    document.getElementById('input_hidden_lim_sup').value = lSup;

    let sumaFondo = 0, cuentaFondo = 0;
    document.querySelectorAll('input[name="fondo_valores[]"]').forEach(inp => {
        inp.classList.remove('input-modificado', 'input-error');
        if (inp.value !== "") {
            const v = parseFloat(inp.value);
            sumaFondo += v;
            cuentaFondo++;
            if (v > lSup || v < lInf) {
                inp.classList.add('input-error');
            } else if (inp.dataset.original !== undefined && parseFloat(inp.value) !== parseFloat(inp.dataset.original)) {
    inp.classList.add('input-modificado');
}
        }
    });

    const promFondo = cuentaFondo > 0 ? (sumaFondo / cuentaFondo) : 0;
    document.getElementById('promedio_display').innerText = promFondo.toFixed(2);
    document.getElementById('input_hidden_prom').value = promFondo.toFixed(2);

document.querySelectorAll('.row-muestra').forEach(fila => {
    let sumaMuestra = 0, cuentaMuestra = 0;

    fila.querySelectorAll('.input-muestra').forEach(input => {
        input.classList.remove('input-modificado');

        const valorNumerico = parseFloat(input.value);

        if (input.value === "" || valorNumerico === 0) {
            input.value = "";
        }

        if (input.value !== "" && !isNaN(valorNumerico) && valorNumerico > 0) {
            sumaMuestra += valorNumerico;
            cuentaMuestra++;

            if (input.dataset.original !== undefined && input.value != input.dataset.original) {
                input.classList.add('input-modificado');
            }
        }
    });

    const cellPromedio = fila.querySelector('.cell-promedio');
    const cellNeto = fila.querySelector('.cell-neto');
    const hiddenNeto = fila.querySelector('.hidden-neto');
    const hiddenEsLim = fila.querySelector('.hidden-es-limite');

    if (cuentaMuestra === 0) {
        cellPromedio.innerText = "-";
        cellNeto.innerText = "-";
        cellNeto.style.backgroundColor = '';
        hiddenNeto.value = "";
    } else {
        const promMuestra = sumaMuestra / cuentaMuestra;
        const promFondo = parseFloat(document.getElementById('input_hidden_prom').value) || 0;
        const LLD = parseFloat(document.getElementById('lld_val').value) || 0;

        const netoNum = promMuestra - promFondo;

        cellPromedio.innerText = promMuestra.toFixed(0);

        if (netoNum < LLD) {
            cellNeto.style.backgroundColor = 'rgb(52 211 153)';
            cellNeto.style.color = 'rgb(15 23 42)';
            cellNeto.innerText = "< " + LLD.toFixed(0);
            hiddenNeto.value = LLD;
            hiddenEsLim.value = "1";
        } else {
            cellNeto.style.backgroundColor = '';
            cellNeto.style.color = '';
            cellNeto.innerText = netoNum.toFixed(0);
            hiddenNeto.value = netoNum.toFixed(0);
            hiddenEsLim.value = "0";
        }
    }
});
    if (typeof actualizarGraficas === "function") {
        actualizarGraficas();
    }
}

window.addEventListener('load', recalcularTodo);

function recalculateManual(input) {
    let valorLimpio = input.value.replace(',', '.');
    let numero = parseFloat(valorLimpio);
    if (!isNaN(numero)) {
        recalcularTodo();
    }
}

function confirmarBorrado() { if (confirm("¿Borrar el registro completo de la BD?")) { document.getElementById('hiddenDeleteBtn').click(); } }
document.addEventListener('DOMContentLoaded', recalcularTodo);

function limpiarSoloValores() {
    const inputFecha = document.querySelector('input[name="db_fecha"]');
const regexFecha = /^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])\/\d{4}$/;

if (!regexFecha.test(inputFecha.value)) {
    alert("Por favor, ingrese una fecha válida en formato dd/mm/aaaa");
    inputFecha.focus();
    return false;
}

    if (inputFecha) inputFecha.value = "";

    const fDesde = document.getElementById('fecha_desde');
    if (fDesde) fDesde.value = "";
    const fHasta = document.getElementById('fecha_hasta');
    if (fHasta) fHasta.value = "";
    const fAlmaDesde = document.getElementById('almafuerte_desde');
    if (fAlmaDesde) fAlmaDesde.value = "";
    const fAlmaHasta = document.getElementById('almafuerte_hasta');
    if (fAlmaHasta) fAlmaHasta.value = "";

    document.querySelectorAll('input[name="fondo_valores[]"], .input-muestra').forEach(input => {
        input.value = "";
        input.dataset.original = "";
        input.classList.remove('input-modificado', 'input-error');
    });

    const txtArea = document.querySelector('textarea[name="db_comentarios"]');
    if (txtArea) txtArea.value = "";

    const cellBkg = document.getElementById('bkg_val_cell');
    const cellH3 = document.getElementById('h3_val_cell');
    if (cellBkg) cellBkg.innerText = "0.00";
    if (cellH3) cellH3.innerText = "0.00";

    const url = new URL(window.location.href);
    url.searchParams.delete('cargar_fecha');
    window.history.replaceState({}, '', url.pathname);

    if (sessionStorage.getItem('es_carga_manual') === 'true') {
        const inputTipo = document.getElementById('db_tipo_carga');
        if(inputTipo) inputTipo.value = "manual";
    }
}

function mascaraFecha(input) {
    let v = input.value.replace(/\D/g, '').slice(0, 8);
    if (v.length >= 5) {
        input.value = `${v.slice(0, 2)}/${v.slice(2, 4)}/${v.slice(4, 8)}`;
    } else if (v.length >= 3) {
        input.value = `${v.slice(0, 2)}/${v.slice(2, 4)}`;
    } else {
        input.value = v;
    }
}

document.querySelectorAll('.input-monitor, #lld_val').forEach(input => {
    input.addEventListener('change', () => {
        const config = {
            lld: document.getElementById('lld_val').value,
            lim_inf: document.getElementById('lim_inf_val').value,
            lim_sup: document.getElementById('lim_sup_val').value,
            bkg_max: document.getElementById('bkg_max').value,
            bkg_min: document.getElementById('bkg_min').value,
            h3_max: document.getElementById('h3_max').value,
            h3_min: document.getElementById('h3_min').value
        };
        localStorage.setItem('config_limites', JSON.stringify(config));
    });
});

function eliminarFila(boton) {
    if (confirm("¿Estás seguro de que deseas eliminar esta fila?")) {
        const fila = boton.closest('tr');
        if (fila) {
            fila.remove();
            if (typeof recalcularTodo === "function") {
                recalcularTodo();
            }
        }
    }
}
</script>

<a href="condensado.php" class="bt2 no-print" style="position:fixed; font-size:16px; top: 6px; left:60%; width:150px; height:35px; cursor:pointer; display:flex; align-items:center; justify-content:center; background:#0369a1; color:#fff; text-decoration:none; border-radius:4px; font-weight:bold;">📋 Condensado</a>

<input type="button" class="bt2 no-print" value=" Actual. N.L. "
    onclick="abrirYCerrarDOSN()"
    style="position:fixed; font-size:20px; top: 6px; left:78%; width:110px; height:35px; cursor:pointer;" />

<form name="formulario" method="get" action="https://<?php echo $direccion?>/indexDOS/indexDOS.php" target="_blank" onclick="javascript:self.close()">
    <input type="submit" class="bt2 no-print" value=" VOLVER "
        style="position:fixed; font-size:20px; top: 6px; left:92%; width:110px; height:35px; cursor:pointer;" />
</form>

<script>
function abrirYCerrarDOSN() {
    var win = window.open('../DOSN/index.php', '_blank');
    if(win) {
        win.focus();
    } else {
        alert("Por favor habilita las ventanas emergentes");
    }
}

function borrarDatoQC(idCampo) {
    if (confirm("¿Deseas borrar este valor?")) {
        const campo = document.getElementById(idCampo);
        campo.value = "";
        recalcularTodo();
        campo.classList.add('input-modificado');
    }
}
</script>

</body>
</html>
