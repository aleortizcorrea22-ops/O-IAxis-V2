<?php
// Conexión a la NUEVA base de datos, separada de syr / syrhe / laboratorio_db
$servername = "localhost";
$username   = "root";       // Usuario por defecto en XAMPP
$password   = "";           // Contraseña por defecto en XAMPP (vacía)
$dbname     = "lab_tritio_v2";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
?>
