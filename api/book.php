<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/h-reservas.php';
require_once __DIR__ . '/../includes/h-pagos.php';
require_once __DIR__ . '/../includes/h-correo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

// ── CSRF verification ─────────────────────────────────────
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida. Recarga la página e intenta de nuevo.']);
    exit;
}

// ── Con abono obligatorio, este camino queda cerrado ───────
// La reserva solo puede nacer desde api/pago_iniciar.php, que es quien
// aparta el cupo contra un pago. Se valida en el servidor y no solo en
// el wizard, para que no se pueda saltar el pago llamando a esta URL.
if (abonoEsObligatorio()) {
    http_response_code(409);
    echo json_encode(['error' => 'Para agendar debes pagar el abono. Recarga la página e intenta de nuevo.']);
    exit;
}

// ── Límite de frecuencia ──────────────────────────────────
// Evita que una misma sesión haga spam de reservas (bots, doble envío, etc.).
$now = time();
if (!empty($_SESSION['last_booking_at']) && ($now - $_SESSION['last_booking_at']) < 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Ya enviaste una solicitud hace un momento. Espera unos segundos e intenta de nuevo.']);
    exit;
}

// ── Leer y validar input ──────────────────────────────────
$in = json_decode(file_get_contents('php://input'), true);

if (!$in) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos.']);
    exit;
}

['errores' => $errores, 'datos' => $datos] = validarDatosReserva($in);

if ($errores) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errores)]);
    exit;
}

// La conexión se abre aparte: si falla, el cliente recibe un 500 genérico
// y no el detalle de la infraestructura.
try {
    $db = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno al guardar la cita. Intenta de nuevo.']);
    exit;
}

try {
    // Devuelve a la agenda los cupos de abonos que nunca se pagaron.
    liberarReservasVencidas($db);

    $reserva = crearReservaPendiente($db, $datos);

    // La cita ya quedó guardada: un problema al avisar por correo no debe
    // convertirse en un 500 para el cliente ni ocultar que sí se reservó.
    avisarPorCorreoSiCorresponde($db, $reserva['appointment_id'], 'solicitud');

    $_SESSION['last_booking_at'] = $now;
    echo json_encode([
        'success'        => true,
        'appointment_id' => $reserva['appointment_id'],
        // Enlace privado para que el cliente siga el estado de su cita.
        'status_url'     => urlEstadoCita($db, $reserva['appointment_id']),
    ]);

} catch (RuntimeException $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('[Blue] Error al guardar la cita: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno al guardar la cita. Intenta de nuevo.']);
}
