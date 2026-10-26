-- Esquema de la base de datos NUEVA (lab_tritio_v2)
-- Totalmente independiente de syr / syrhe / laboratorio_db.
-- Ejecutar una sola vez para crear la base y las tablas.

CREATE DATABASE IF NOT EXISTS lab_tritio_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab_tritio_v2;

-- Tabla con la IP del servidor/panel (usada por el botón "VOLVER" / "Actual. N.L.")
CREATE TABLE IF NOT EXISTS ip (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    IP VARCHAR(255) NOT NULL
);
-- Ejemplo de carga inicial (ajustar la IP real del servidor):
-- INSERT INTO ip (ID, IP) VALUES (1, '127.0.0.1');

-- Registro de visitas a la página (auditoría simple)
CREATE TABLE IF NOT EXISTS registro_visitas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_usuario VARCHAR(100),
    dispositivo VARCHAR(255),
    fecha_acceso DATETIME,
    accion_realizada VARCHAR(255)
);

-- Catálogo dinámico de siglas de muestra -> nombre completo.
-- Se autocompleta solo cuando aparece un ID nuevo en un archivo cargado.
CREATE TABLE IF NOT EXISTS muestras_catalogo (
    sigla VARCHAR(50) PRIMARY KEY,
    nombre_completo VARCHAR(150) NOT NULL
);

-- Carga inicial con los IDs y nombres reales de las estaciones (podés editar nombre_completo cuando quieras).
-- Si la base ya existía con estos IDs cargados por autodetección (sigla = nombre_completo),
-- este INSERT con ON DUPLICATE KEY UPDATE corrige el nombre a su valor real.
INSERT INTO muestras_catalogo (sigla, nombre_completo) VALUES
    ('CTM',  'TORRE MET.'),
    ('CCR3', 'C. RIO III'),
    ('CFA',  'F. AEREA'),
    ('CVDD', 'VILLA DEL DIQUE'),
    ('CA',   'ALMAFUERTE'),
    ('CL',   'LABORATORIO')
ON DUPLICATE KEY UPDATE nombre_completo = VALUES(nombre_completo);

-- Tabla principal: un registro por reporte cargado/guardado
CREATE TABLE IF NOT EXISTS reportes_analisis (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fecha_archivo VARCHAR(10) NOT NULL,      -- dd/mm/aaaa (Fecha Reporte)
    modelo VARCHAR(100),
    serial_n VARCHAR(100),
    tiempo VARCHAR(50),
    semana INT,
    lim_inf_fondo DECIMAL(10,4),
    lim_sup_fondo DECIMAL(10,4),
    promedio_fondo DECIMAL(10,4),
    bkg_max DECIMAL(10,4),
    bkg_min DECIMAL(10,4),
    h3_max DECIMAL(10,4),
    h3_min DECIMAL(10,4),
    bkg_real DECIMAL(10,4),
    h3_real DECIMAL(10,4),
    comentarios TEXT,
    fecha_desde VARCHAR(10),                 -- dd/mm/aaaa
    fecha_hasta VARCHAR(10),                 -- dd/mm/aaaa
    almafuerte_fecha_desde VARCHAR(10),      -- dd/mm/aaaa
    almafuerte_fecha_hasta VARCHAR(10),      -- dd/mm/aaaa
    tipo_carga VARCHAR(20) DEFAULT 'archivo',
    UNIQUE KEY uniq_reporte (fecha_archivo, modelo, serial_n)
);

CREATE TABLE IF NOT EXISTS fondos_detalle (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reporte_id INT NOT NULL,
    numero_fondo INT NOT NULL,
    valor_bq DECIMAL(10,4),
    FOREIGN KEY (reporte_id) REFERENCES reportes_analisis(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS muestras_detalle (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reporte_id INT NOT NULL,
    id_muestra VARCHAR(50) NOT NULL,
    neto DECIMAL(10,4),
    es_limite TINYINT(1) DEFAULT 0,
    lectura1 DECIMAL(10,4),
    lectura2 DECIMAL(10,4),
    lectura3 DECIMAL(10,4),
    FOREIGN KEY (reporte_id) REFERENCES reportes_analisis(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS datos_coinc (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_reporte INT NOT NULL,
    FOREIGN KEY (id_reporte) REFERENCES reportes_analisis(id) ON DELETE CASCADE
);

-- ============================================================
-- Informe "Concentración de Tritio en Condensado de Humedad Ambiente"
-- Replica de la planilla Excel (1er/2do/3er/4to trimestre, inf avanc, PROMEDIOS)
-- ============================================================

-- Una fila por semana de muestreo
CREATE TABLE IF NOT EXISTS informe_condensado (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trimestre TINYINT NOT NULL,              -- 1, 2, 3 o 4
    anio INT NOT NULL,
    fecha_desde VARCHAR(10),                 -- dd/mm/aaaa (muestreo: Torre Met/Lab/C.Rio III/F.Aerea/VDD)
    fecha_hasta VARCHAR(10),
    fecha_medicion VARCHAR(10),
    hum_pct DECIMAL(6,4),                    -- "Hum. sin %" como fracción (ej 0.61)
    temp_c DECIMAL(5,2),
    almafuerte_fecha_desde VARCHAR(10),       -- Almafuerte tiene su propio período
    almafuerte_fecha_hasta VARCHAR(10),
    almafuerte_hum_pct DECIMAL(6,4),
    almafuerte_temp_c DECIMAL(5,2),
    observaciones VARCHAR(255)
);

-- Bq/L cargado por estación para cada semana (CTM, CL, CCR3, CFA, CVDD, CA)
CREATE TABLE IF NOT EXISTS condensado_detalle (
    id INT PRIMARY KEY AUTO_INCREMENT,
    informe_id INT NOT NULL,
    estacion VARCHAR(10) NOT NULL,
    bq_l DECIMAL(10,4),
    FOREIGN KEY (informe_id) REFERENCES informe_condensado(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_estacion_informe (informe_id, estacion)
);
