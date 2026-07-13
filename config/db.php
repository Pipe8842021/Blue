<?php
// Zona horaria de Colombia (no observa horario de verano → UTC-5 fijo)
date_default_timezone_set('America/Bogota');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'blue_db');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '-05:00'"); // CURDATE()/NOW() en hora de Colombia
        } catch (PDOException $e) {
            // Registrar el detalle solo en el log (nunca al usuario) y relanzar
            // una excepción genérica: cada página decide cómo responder
            // (las públicas pueden degradar; las APIs devuelven su propio JSON).
            error_log('[Blue] Error de conexion con la BD: ' . $e->getMessage());
            throw new RuntimeException('No se pudo conectar con la base de datos.');
        }
    }
    return $pdo;
}
