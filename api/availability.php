<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

$weekStart = $_GET['week_start'] ?? null;

// Validar formato YYYY-MM-DD
if (!$weekStart || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
    // Si no se indica, usar el lunes de la semana actual
    $weekStart = date('Y-m-d', strtotime('monday this week'));
}

$weekEnd = date('Y-m-d', strtotime($weekStart . ' +5 days')); // Lun–Sáb

try {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(date,'%Y-%m-%d')             AS date,
                DATE_FORMAT(time_start,'%H:%i')           AS time_start,
                DATE_FORMAT(time_end,'%H:%i')             AS time_end
         FROM appointments
         WHERE date BETWEEN ? AND ?
           AND status IN ('pending','confirmed')
         ORDER BY date, time_start"
    );
    $stmt->execute([$weekStart, $weekEnd]);
    $occupied = $stmt->fetchAll();

    echo json_encode(['occupied' => $occupied], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar disponibilidad.']);
}
