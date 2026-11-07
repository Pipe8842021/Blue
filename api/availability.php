<?php
header('Content-Type: application/json; charset=utf-8');
// Sin CORS: este endpoint solo lo consume booking.js del mismo origen.
// Exponerlo con "*" permitía leer la agenda de ocupación desde cualquier sitio externo.

// ============================================================
// Disponibilidad de una semana para la reserva en línea.
//
//   GET ?week_start=YYYY-MM-DD   lunes de la semana (por defecto, la actual)
//       &services=3,5            servicios elegidos (definen duración y quién los hace)
//       &staff_id=2              profesional elegida; 0 = «cualquiera disponible»
//       &otro=1                  «Otro servicio» (sin servicios): usa la duración configurada
//
// Responde el estado de cada horario (libre, ocupado, no_cabe, fuera, pasado)
// calculado con las agendas de cada profesional. Ver includes/h-agendas.php.
// De cada profesional solo se expone id, nombre e iniciales.
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-pagos.php';   // liberarReservasVencidas(); trae h-reservas y h-agendas

$weekStart = $_GET['week_start'] ?? null;

// Validar formato YYYY-MM-DD
if (!$weekStart || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) || !strtotime($weekStart)) {
    // Si no se indica, usar el lunes de la semana actual
    $weekStart = date('Y-m-d', strtotime('monday this week'));
}

// Como mucho 20 servicios: nadie agenda más en una cita y acota el trabajo del servidor.
$servicios = array_slice(idsLimpios(explode(',', (string)($_GET['services'] ?? ''))), 0, 20);
$staffId   = max(0, (int)($_GET['staff_id'] ?? 0));
// «Otro servicio»: no hay servicios del catálogo; la duración es la configurada en el negocio.
$esOtro    = !empty($_GET['otro']) && !$servicios;

try {
    $db = getDB();

    // Antes de dibujar la agenda se devuelven a la bolsa los horarios
    // apartados por abonos que nunca se pagaron.
    liberarReservasVencidas($db);

    echo json_encode(disponibilidadSemana($db, $weekStart, $servicios, $staffId, $esOtro), JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[Blue] Error al consultar disponibilidad: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar disponibilidad.']);
}
