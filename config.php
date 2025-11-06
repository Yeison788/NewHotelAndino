<?php
// 🔹 Detectar entorno: Docker vs Producción
if (getenv('DOCKER_ENV') || file_exists('/.dockerenv')) {
    // Entorno Docker
    $servername = "db";
    $username   = "andino_user";
    $password   = "andino_pass";
    $dbname     = "hotelandino";
} else {
    // Entorno Producción o local real
    $servername = "localhost";
    $username   = "hotelandino_user";
    $password   = "password"; // <-- cambia por la real
    $dbname     = "hotelandino";
}

// Conexión
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Validar conexión
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Charset recomendado para acentos y emojis
mysqli_set_charset($conn, 'utf8mb4');

// Debug opcional (solo para desarrollo)
// echo "✅ Conexión establecida a la BD: $dbname";
?>